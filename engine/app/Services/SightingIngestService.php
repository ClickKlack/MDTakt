<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SightingMatch;
use App\Enums\SightingStatus;
use App\Models\MdktRoute;
use App\Models\Sighting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nimmt Sichtungen aus MDKursTracker entgegen und hält ihre Zuordnung aktuell.
 *
 * Der Tracker ruft sofort nach dem Speichern auf und holt Fehlgeschlagenes per Cron nach
 * (entschieden 26.09.2026). Ein Aufruf trägt deshalb eine bis viele Sichtungen, und dieselbe
 * Sichtung kann mehrfach kommen — `mdkt_recording_id` macht den Eingang idempotent.
 *
 * **Auto-Bestätigung:** Hängt der gesichtete Kurs schon an der Fahrt, gibt es nichts zu
 * entscheiden. Die Sichtung wird `confirmed` und zählt als Beleg, landet aber nicht in der
 * Warteschlange. Ein Treffer erst in der Folgeversion wird nie so bestätigt — dort ist gerade
 * fraglich, ob die Fahrt dieselbe ist.
 */
final class SightingIngestService
{
    /**
     * Nach so vielen Importen ohne Treffer wird aus „wartet auf Fahrplan" „keine Fahrt"
     * (entschieden 26.09.2026). Bei wöchentlichem Feed etwa ein bis zwei Wochen.
     */
    public const ATTEMPTS_BEFORE_NO_TRIP = 2;

    public function __construct(
        private readonly SightingMatcher $matcher,
        private readonly CourseLookup $courses,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $trips  Laufwege, je Fingerprint einmal
     * @param  array<int, array<string, mixed>>  $sightings
     * @return array{
     *     received: array{trips: int, sightings: int},
     *     results: array<int, array{mdkt_recording_id: int, outcome: string, match: ?string, status: ?string, consolidated_trip_id: ?int}>,
     *     unmatched_fingerprints: array<int, string>,
     *     watermark: array{max_recording_id: ?int, max_observed_at: ?string}
     * }
     */
    public function ingest(array $trips, array $sightings): array
    {
        return DB::transaction(function () use ($trips, $sightings): array {
            $routen = [];

            foreach ($trips as $trip) {
                $route = $this->storeRoute($trip);
                $routen[$route->fingerprint] = $route;
            }

            $ergebnisse = [];
            $ohneTreffer = [];

            foreach ($sightings as $daten) {
                $fingerprint = (string) $daten['schedule_fingerprint'];
                $route = $routen[$fingerprint] ??= MdktRoute::query()->where('fingerprint', $fingerprint)->first();

                if ($route === null) {
                    Log::warning('Sighting references unknown route fingerprint', [
                        'mdkt_recording_id' => $daten['mdkt_recording_id'],
                        'fingerprint' => $fingerprint,
                    ]);
                    $ergebnisse[] = $this->result((int) $daten['mdkt_recording_id'], 'unknown_fingerprint');

                    continue;
                }

                [$sichtung, $ausgang] = $this->storeSighting($daten, $route);

                if (! $sichtung->match->hasTrip()) {
                    $ohneTreffer[$fingerprint] = true;
                }

                $ergebnisse[] = $this->result($sichtung->mdkt_recording_id, $ausgang, $sichtung);
            }

            $zusammenfassung = [
                'received' => ['trips' => count($trips), 'sightings' => count($sightings)],
                'results' => $ergebnisse,
                'unmatched_fingerprints' => array_keys($ohneTreffer),
                'watermark' => $this->watermark($sightings),
            ];

            Log::info('Sightings ingested', [
                'trips' => count($trips),
                'sightings' => count($sightings),
                'outcomes' => array_count_values(array_column($ergebnisse, 'outcome')),
                'unmatched_fingerprints' => count($ohneTreffer),
            ]);

            return $zusammenfassung;
        });
    }

    /**
     * Ordnet offene Sichtungen erneut zu — am Ende jedes GTFS-Imports, weil der Feed einen
     * geänderten Laufweg oft erst Tage nach dem Tracker kennt.
     *
     * Betroffen sind offene Sichtungen ohne sicheren Treffer und jede Sichtung, deren Fahrt der
     * Import entfernt hat. Eine entschiedene Sichtung behält ihren Status; nur ihre Fahrt wird
     * wiedergefunden.
     *
     * @param  bool  $countAttempt  false beim Aufruf von Hand — der zählt nicht als Import
     * @return array{checked: int, matched: int, still_open: int, confirmed: int}
     */
    public function rematchOpen(bool $countAttempt = true): array
    {
        $zahlen = ['checked' => 0, 'matched' => 0, 'still_open' => 0, 'confirmed' => 0];

        Sighting::query()
            ->with('route')
            ->where(function ($q): void {
                $q->where('status', SightingStatus::Pending->value)
                    ->whereIn('match', [
                        SightingMatch::Waiting->value,
                        SightingMatch::NoTrip->value,
                        SightingMatch::Ambiguous->value,
                        SightingMatch::MatchedNextVersion->value,
                    ]);
            })
            ->orWhere(function ($q): void {
                $q->whereNull('consolidated_trip_id')
                    ->whereIn('match', [SightingMatch::Matched->value, SightingMatch::MatchedNextVersion->value]);
            })
            ->chunkById(200, function ($teil) use (&$zahlen, $countAttempt): void {
                foreach ($teil as $sichtung) {
                    $vorher = $sichtung->status;
                    $this->applyMatch($sichtung, $countAttempt);
                    $sichtung->save();

                    $zahlen['checked']++;
                    $sichtung->match->hasTrip() ? $zahlen['matched']++ : $zahlen['still_open']++;

                    if ($vorher !== SightingStatus::Confirmed && $sichtung->status === SightingStatus::Confirmed) {
                        $zahlen['confirmed']++;
                    }
                }
            });

        Log::info('Open sightings rematched', $zahlen + ['counted_as_import' => $countAttempt]);

        return $zahlen;
    }

    /**
     * Setzt offene Sichtungen auf `confirmed`, deren Kurs inzwischen an der Fahrt hängt — nach
     * einer Annahme oder Pflege im Fahrplan.
     *
     * @param  array<int, int>  $tripIds
     */
    public function confirmMatching(array $tripIds): int
    {
        if ($tripIds === []) {
            return 0;
        }

        $bestaetigt = 0;

        Sighting::query()
            ->where('status', SightingStatus::Pending->value)
            ->where('match', SightingMatch::Matched->value)
            ->whereIn('consolidated_trip_id', $tripIds)
            ->get()
            ->each(function (Sighting $sichtung) use (&$bestaetigt): void {
                if ($this->evaluate($sichtung)) {
                    $sichtung->save();
                    $bestaetigt++;
                }
            });

        return $bestaetigt;
    }

    /**
     * Sucht die Fahrt und schreibt das Ergebnis in die Sichtung (ohne zu speichern).
     */
    public function applyMatch(Sighting $sichtung, bool $countAttempt = false): void
    {
        $ergebnis = $this->matcher->match(
            $sichtung->route,
            $sichtung->line,
            $sichtung->hafas_stop_id,
            $sichtung->departure_planned,
        );

        if ($ergebnis->match !== null && $ergebnis->match->hasTrip()) {
            $sichtung->match = $ergebnis->match;
            $sichtung->consolidated_trip_id = $ergebnis->tripId;
            $sichtung->trip_signature = $ergebnis->signature;
            $sichtung->match_attempts = 0;
        } else {
            $sichtung->consolidated_trip_id = null;
            $sichtung->trip_signature = null;

            if ($countAttempt) {
                $sichtung->match_attempts++;
            }

            $sichtung->match = match (true) {
                $ergebnis->match === SightingMatch::Ambiguous => SightingMatch::Ambiguous,
                $sichtung->match_attempts >= self::ATTEMPTS_BEFORE_NO_TRIP => SightingMatch::NoTrip,
                default => SightingMatch::Waiting,
            };

            Log::warning('Sighting without trip match', [
                'mdkt_recording_id' => $sichtung->mdkt_recording_id,
                'line' => $sichtung->line,
                'fingerprint' => $sichtung->route->fingerprint,
                'operating_date' => $ergebnis->operatingDate,
                'reason' => $ergebnis->reason,
                'attempts' => $sichtung->match_attempts,
            ]);
        }

        $this->evaluate($sichtung);
    }

    /**
     * Auto-Bestätigung: offen, sicherer Treffer und dieselbe Nummer wie lokal.
     *
     * @return bool true, wenn der Status umgesprungen ist
     */
    private function evaluate(Sighting $sichtung): bool
    {
        if ($sichtung->status !== SightingStatus::Pending
            || $sichtung->match !== SightingMatch::Matched
            || $sichtung->consolidated_trip_id === null) {
            return false;
        }

        $lokal = $this->courses->forTrips([$sichtung->consolidated_trip_id])[$sichtung->consolidated_trip_id] ?? null;

        if ($lokal === null || ! self::sameNumber($lokal['number'], $sichtung->course_number)) {
            return false;
        }

        $sichtung->status = SightingStatus::Confirmed;
        $sichtung->decided_at = CarbonImmutable::now();

        Log::info('Sighting confirmed automatically', [
            'mdkt_recording_id' => $sichtung->mdkt_recording_id,
            'consolidated_trip_id' => $sichtung->consolidated_trip_id,
            'course_number' => $sichtung->course_number,
        ]);

        return true;
    }

    /**
     * „03" und „3" sind derselbe Kurs: Der Tracker speichert zweistellig, die Pflege nimmt, was
     * getippt wird.
     */
    public static function sameNumber(string $a, string $b): bool
    {
        $norm = static function (string $n): string {
            $n = trim($n);

            return ctype_digit($n) ? (ltrim($n, '0') ?: '0') : mb_strtolower($n);
        };

        return $norm($a) === $norm($b);
    }

    /**
     * @param  array<string, mixed>  $trip
     */
    private function storeRoute(array $trip): MdktRoute
    {
        $halte = array_map(static fn (array $s): array => [
            'seq' => (int) $s['seq'],
            'hafas_stop_id' => (string) $s['hafas_stop_id'],
            'stop_name' => $s['stop_name'] ?? null,
            'line' => (string) $s['line'],
            'arrival_planned' => $s['arrival_planned'] ?? null,
            'departure_planned' => $s['departure_planned'] ?? null,
        ], $trip['stops']);

        return MdktRoute::query()->updateOrCreate(
            ['fingerprint' => (string) $trip['schedule_fingerprint']],
            [
                'mdkt_trip_id' => $trip['mdkt_trip_id'] ?? null,
                'line' => (string) $trip['line'],
                'direction' => $trip['direction'] ?? null,
                'stops' => $halte,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $daten
     * @return array{0: Sighting, 1: string} Sichtung und Ausgang (created|updated|unchanged)
     */
    private function storeSighting(array $daten, MdktRoute $route): array
    {
        $werte = [
            'mdkt_route_id' => $route->id,
            'line' => (string) $daten['line'],
            'course_number' => trim((string) $daten['course_number']),
            'hafas_stop_id' => (string) $daten['hafas_stop_id'],
            // Der Tracker sendet den Namen an der Sichtung nicht immer — im Laufweg steht er.
            'stop_name' => $daten['stop_name'] ?? self::stopNameFromRoute($route, (string) $daten['hafas_stop_id']),
            'service_date' => (string) $daten['service_date'],
            'observed_at' => CarbonImmutable::parse((string) $daten['observed_at']),
            'departure_planned' => CarbonImmutable::parse((string) $daten['departure_planned']),
            'departure_actual' => isset($daten['departure_actual']) ? CarbonImmutable::parse((string) $daten['departure_actual']) : null,
        ];

        $sichtung = Sighting::query()->where('mdkt_recording_id', (int) $daten['mdkt_recording_id'])->first();

        if ($sichtung !== null && ! $this->changesMatter($sichtung, $werte)) {
            return [$sichtung, 'unchanged'];
        }

        $ausgang = $sichtung === null ? 'created' : 'updated';
        $sichtung ??= new Sighting(['mdkt_recording_id' => (int) $daten['mdkt_recording_id']]);

        // Eine geänderte Sichtung ist eine neue Aussage — eine frühere Entscheidung galt der alten.
        $sichtung->fill($werte + [
            'status' => SightingStatus::Pending,
            'decided_at' => null,
            'decision_note' => null,
            'match_attempts' => 0,
        ]);
        $sichtung->setRelation('route', $route);

        $this->applyMatch($sichtung);
        $sichtung->save();

        return [$sichtung, $ausgang];
    }

    /**
     * Name des Halts aus dem Laufweg, über die HAFAS-ID. Ohne „Magdeburg, " — die Prüfliste zeigt nur
     * Magdeburger Halte, und der Ortspräfix nähme dort nur Platz weg.
     */
    public static function stopNameFromRoute(MdktRoute $route, string $hafasStopId): ?string
    {
        foreach ($route->stops as $halt) {
            if ((string) $halt['hafas_stop_id'] === $hafasStopId && ! empty($halt['stop_name'])) {
                return (string) preg_replace('/^Magdeburg,\s*/u', '', (string) $halt['stop_name']);
            }
        }

        return null;
    }

    /**
     * Ändert sich etwas, das die Zuordnung oder die Aussage betrifft? Eine nachgereichte
     * Ist-Abfahrt allein öffnet eine entschiedene Sichtung nicht wieder.
     *
     * @param  array<string, mixed>  $werte
     */
    private function changesMatter(Sighting $sichtung, array $werte): bool
    {
        return $sichtung->mdkt_route_id !== $werte['mdkt_route_id']
            || $sichtung->line !== $werte['line']
            || $sichtung->course_number !== $werte['course_number']
            || $sichtung->hafas_stop_id !== $werte['hafas_stop_id']
            || $sichtung->service_date->toDateString() !== $werte['service_date']
            || ! $sichtung->departure_planned->equalTo($werte['departure_planned']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sightings
     * @return array{max_recording_id: ?int, max_observed_at: ?string}
     */
    private function watermark(array $sightings): array
    {
        if ($sightings === []) {
            return ['max_recording_id' => null, 'max_observed_at' => null];
        }

        $spaeteste = max(array_map(
            static fn (array $s): int => CarbonImmutable::parse((string) $s['observed_at'])->getTimestamp(),
            $sightings,
        ));

        return [
            'max_recording_id' => max(array_map(static fn (array $s): int => (int) $s['mdkt_recording_id'], $sightings)),
            'max_observed_at' => CarbonImmutable::createFromTimestampUTC($spaeteste)->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return array{mdkt_recording_id: int, outcome: string, match: ?string, status: ?string, consolidated_trip_id: ?int}
     */
    private function result(int $recordingId, string $ausgang, ?Sighting $sichtung = null): array
    {
        return [
            'mdkt_recording_id' => $recordingId,
            'outcome' => $ausgang,
            'match' => $sichtung?->match->value,
            'status' => $sichtung?->status->value,
            'consolidated_trip_id' => $sichtung?->consolidated_trip_id,
        ];
    }
}
