<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SightingMatch;
use App\Enums\SightingStatus;
use App\Models\ConsolidatedTrip;
use App\Models\Sighting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Die Prüfliste der Sichtungen: filtern, mit den lokalen Daten vergleichen, annehmen, ablehnen.
 *
 * Der **Vergleich** wird bei jeder Abfrage berechnet, nicht gespeichert: `same` (Kurs hängt
 * schon an der Fahrt), `differs` (anderer Kurs), `none` (Fahrt ohne Kurs), `no_trip`.
 *
 * **Annehmen** setzt die gesichtete Nummer an die **ganze Kette** der Fahrt — derselbe Weg wie die
 * Kurs-Eingabe im Fahrplan (KURSE §2 K2). Weicht sie ab, wird die Kette umnummeriert
 * (entschieden 26.09.2026); die Oberfläche fragt vorher mit der Kettenlänge nach.
 */
final class SightingReviewService
{
    /** Ansichten der Liste — `open` ist die Warteschlange. */
    public const STATES = ['open', 'waiting', 'confirmed', 'accepted', 'rejected', 'all'];

    public function __construct(
        private readonly CourseService $courses,
        private readonly CourseLookup $lookup,
        private readonly TripLinkService $links,
        private readonly ConsolidatedTripInfoResolver $tripInfo,
        private readonly SightingIngestService $ingest,
    ) {}

    /**
     * @param  array{state?: string, line?: ?string, date_from?: ?string, date_to?: ?string, differs_only?: bool}  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function list(array $filter, int $page = 1, int $perPage = 50): array
    {
        $query = Sighting::query()->with('route');
        $this->applyState($query, $filter['state'] ?? 'open');

        if (! empty($filter['line'])) {
            $query->where('line', $filter['line']);
        }

        if (! empty($filter['date_from'])) {
            $query->whereDate('service_date', '>=', $filter['date_from']);
        }

        if (! empty($filter['date_to'])) {
            $query->whereDate('service_date', '<=', $filter['date_to']);
        }

        if (! empty($filter['differs_only'])) {
            $this->onlyDiffering($query);
        }

        $seite = $query
            ->orderByDesc('service_date')
            ->orderByDesc('departure_planned')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        /** @var array<int, Sighting> $sichtungen */
        $sichtungen = $seite->items();

        return [
            'items' => $this->describe($sichtungen),
            'meta' => [
                'current_page' => $seite->currentPage(),
                'last_page' => $seite->lastPage(),
                'per_page' => $seite->perPage(),
                'total' => $seite->total(),
            ],
        ];
    }

    /**
     * Zahlen für die Navigation: die Warteschlange und die, die noch auf den Fahrplan warten.
     *
     * @return array{open: int, waiting: int}
     */
    public function counts(): array
    {
        $offen = Sighting::query();
        $this->applyState($offen, 'open');
        $wartend = Sighting::query();
        $this->applyState($wartend, 'waiting');

        return ['open' => $offen->count(), 'waiting' => $wartend->count()];
    }

    /**
     * @param  array<int, int>  $ids
     * @return array{accepted: int, trips_assigned: int, confirmed_others: int}
     *
     * @throws InvalidArgumentException wenn eine Sichtung nicht annehmbar ist
     */
    public function accept(array $ids): array
    {
        $sichtungen = Sighting::query()->whereIn('id', $ids)->get();
        $this->assertComplete($sichtungen->count(), $ids);

        $gruppen = [];

        foreach ($sichtungen as $s) {
            if ($s->status !== SightingStatus::Pending) {
                throw new InvalidArgumentException("Sichtung {$s->id} ist schon entschieden.");
            }

            if (! $s->match->hasTrip() || $s->consolidated_trip_id === null) {
                throw new InvalidArgumentException("Sichtung {$s->id} hat keine Fahrt — sie lässt sich nur ablehnen.");
            }

            $bisher = $gruppen[$s->consolidated_trip_id][0] ?? null;

            if ($bisher !== null && ! SightingIngestService::sameNumber($bisher->course_number, $s->course_number)) {
                throw new InvalidArgumentException('Für dieselbe Fahrt sind verschiedene Kursnummern ausgewählt.');
            }

            $gruppen[$s->consolidated_trip_id][] = $s;
        }

        $fahrtenGesetzt = 0;
        $geaenderteFahrten = [];

        DB::transaction(function () use ($gruppen, &$fahrtenGesetzt, &$geaenderteFahrten): void {
            $jetzt = CarbonImmutable::now();

            foreach ($gruppen as $tripId => $gruppe) {
                $fahrt = ConsolidatedTrip::query()->with('lineVersion')->findOrFail($tripId);
                $nummer = $gruppe[0]->course_number;

                $kurs = $this->courses->findOrCreateForChain($fahrt, $nummer);
                $ergebnis = $this->courses->assign($fahrt, $kurs);

                $fahrtenGesetzt += $ergebnis['trips_assigned'];
                array_push($geaenderteFahrten, ...$ergebnis['trip_ids']);

                foreach ($gruppe as $s) {
                    $s->status = SightingStatus::Accepted;
                    $s->decided_at = $jetzt;
                    $s->save();
                }

                Log::info('Sighting accepted, course set on chain', [
                    'sighting_ids' => array_map(static fn (Sighting $s): int => $s->id, $gruppe),
                    'consolidated_trip_id' => $tripId,
                    'course_number' => $nummer,
                    'chain_length' => $ergebnis['trips_assigned'],
                ]);
            }
        });

        // Andere offene Sichtungen derselben Kette stimmen jetzt womöglich schon.
        $bestaetigt = $this->ingest->confirmMatching(array_values(array_unique($geaenderteFahrten)));

        return ['accepted' => $sichtungen->count(), 'trips_assigned' => $fahrtenGesetzt, 'confirmed_others' => $bestaetigt];
    }

    /**
     * @param  array<int, int>  $ids
     * @return array{rejected: int}
     */
    public function reject(array $ids, ?string $note = null): array
    {
        $sichtungen = Sighting::query()->whereIn('id', $ids)->get();
        $this->assertComplete($sichtungen->count(), $ids);

        foreach ($sichtungen as $s) {
            if ($s->status !== SightingStatus::Pending) {
                throw new InvalidArgumentException("Sichtung {$s->id} ist schon entschieden.");
            }
        }

        Sighting::query()->whereIn('id', $ids)->update([
            'status' => SightingStatus::Rejected->value,
            'decided_at' => CarbonImmutable::now(),
            'decision_note' => $note,
        ]);

        Log::info('Sightings rejected', ['sighting_ids' => $ids, 'note' => $note]);

        return ['rejected' => count($ids)];
    }

    /**
     * Die Zeilen der Liste mit Fahrt, lokalem Kurs und Vergleich.
     *
     * @param  array<int, Sighting>  $sichtungen
     * @return array<int, array<string, mixed>>
     */
    public function describe(array $sichtungen): array
    {
        $tripIds = array_values(array_filter(array_map(
            static fn (Sighting $s): ?int => $s->consolidated_trip_id,
            $sichtungen,
        )));

        $fahrten = $this->tripInfo->forIds($tripIds);
        $kurse = $this->lookup->forTrips($tripIds);
        $versionen = $tripIds === [] ? collect() : DB::table('consolidated_trips as ct')
            ->join('line_versions as lv', 'lv.id', '=', 'ct.line_version_id')
            ->whereIn('ct.id', $tripIds)
            ->select('ct.id', 'lv.day_type', 'lv.period_id')
            ->get()
            ->keyBy('id');

        $ketten = [];

        return array_map(function (Sighting $s) use ($fahrten, $kurse, $versionen, &$ketten): array {
            $id = $s->consolidated_trip_id;
            $fahrt = $id !== null ? ($fahrten[$id] ?? null) : null;
            $kurs = $id !== null ? ($kurse[$id] ?? null) : null;
            $vergleich = $this->comparison($s, $kurs);

            // Die Kettenlänge braucht nur, wer annehmen kann — sie steht im Rückfrage-Hinweis.
            $kette = null;

            if ($fahrt !== null && $s->status === SightingStatus::Pending) {
                $kette = $ketten[$id] ??= count($this->links->chainFor(ConsolidatedTrip::query()->findOrFail($id)));
            }

            return [
                'id' => $s->id,
                'mdkt_recording_id' => $s->mdkt_recording_id,
                'line' => $s->line,
                'course_number' => $s->course_number,
                'display' => $s->line.'/'.$s->course_number,
                'hafas_stop_id' => $s->hafas_stop_id,
                'stop_name' => $s->stop_name,
                'service_date' => $s->service_date->toDateString(),
                'observed_at' => $s->observed_at->utc()->toIso8601ZuluString(),
                'departure_planned' => $s->departure_planned->utc()->toIso8601ZuluString(),
                'departure_actual' => $s->departure_actual?->utc()->toIso8601ZuluString(),
                'route_fingerprint' => $s->route->fingerprint,
                'match' => $s->match->value,
                'match_attempts' => $s->match_attempts,
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'decided_at' => $s->decided_at?->utc()->toIso8601ZuluString(),
                'decision_note' => $s->decision_note,
                'trip' => $fahrt === null ? null : [
                    'id' => $fahrt['id'],
                    'line' => $fahrt['line'],
                    'line_version_id' => $fahrt['line_version_id'],
                    'version_no' => $fahrt['version_no'],
                    'day_type' => $versionen[$id]->day_type ?? null,
                    'period_id' => isset($versionen[$id]) ? (int) $versionen[$id]->period_id : null,
                    'start_stop' => $fahrt['start_stop'],
                    'end_stop' => $fahrt['end_stop'],
                    'departure_time' => $fahrt['departure_time'],
                    'arrival_time' => $fahrt['arrival_time'],
                ],
                'local_course' => $kurs === null ? null : [
                    'id' => $kurs['id'],
                    'number' => $kurs['number'],
                    'display' => ($fahrt['line'] ?? $s->line).'/'.$kurs['number'],
                ],
                'comparison' => $vergleich,
                'chain_trip_count' => $kette,
            ];
        }, $sichtungen);
    }

    /**
     * @param  array{id: int, number: string}|null  $kurs
     */
    private function comparison(Sighting $s, ?array $kurs): string
    {
        if ($s->consolidated_trip_id === null) {
            return 'no_trip';
        }

        if ($kurs === null) {
            return 'none';
        }

        return SightingIngestService::sameNumber($kurs['number'], $s->course_number) ? 'same' : 'differs';
    }

    /**
     * @param  Builder<Sighting>  $query
     */
    private function applyState(Builder $query, string $state): void
    {
        match ($state) {
            // Die Warteschlange: offen und entscheidbar. Was noch auf den Fahrplan wartet, stünde
            // dort nur im Weg — es kann sich mit dem nächsten Import von selbst klären.
            'open' => $query->where('status', SightingStatus::Pending->value)
                ->where('match', '!=', SightingMatch::Waiting->value),
            'waiting' => $query->where('status', SightingStatus::Pending->value)
                ->where('match', SightingMatch::Waiting->value),
            'confirmed', 'accepted', 'rejected' => $query->where('status', $state),
            'all' => null,
            default => throw new InvalidArgumentException("Unbekannte Ansicht: {$state}"),
        };
    }

    /**
     * Nur Sichtungen, deren Fahrt einen **anderen** Kurs trägt. In SQL, damit die Seitenzählung
     * stimmt. Führende Nullen zählen nicht („03" = „3") — `ltrim` gibt es in PostgreSQL und SQLite.
     *
     * @param  Builder<Sighting>  $query
     */
    private function onlyDiffering(Builder $query): void
    {
        $query->whereExists(function ($sub): void {
            $sub->select(DB::raw(1))
                ->from('course_trips as kt')
                ->join('courses as c', 'c.id', '=', 'kt.course_id')
                ->whereColumn('kt.consolidated_trip_id', 'sightings.consolidated_trip_id')
                ->whereRaw("lower(ltrim(c.number, '0')) <> lower(ltrim(sightings.course_number, '0'))");
        });
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function assertComplete(int $gefunden, array $ids): void
    {
        if ($gefunden !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Mindestens eine Sichtung existiert nicht.');
        }
    }
}
