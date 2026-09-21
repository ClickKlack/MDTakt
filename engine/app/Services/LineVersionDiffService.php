<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LineVersion;
use App\Support\GtfsTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Vergleicht zwei Fahrplan-Versionen derselben Linie und desselben Fahrplantyps.
 *
 * Eine Version ist definiert als die sortierte Menge ihrer Fahrt-Signaturen — der Vergleich
 * beginnt deshalb dort. Zwei Feinheiten entscheiden über die Brauchbarkeit des Ergebnisses:
 *
 * **Multiset, nicht Menge.** Das Schema lässt mehrere Fahrten mit derselben Signatur je Version
 * zu (die Migration begründet das ausdrücklich). Ein Mengenvergleich verlöre Duplikate.
 *
 * **Die Signatur hat eine Blindstelle.** Sie lautet `SHA(Linie | Typ | HH:MM-Abfahrtssequenz)` —
 * ohne Halte und nur minutengenau. Ein reiner Laufweg-Wechsel bei gleichen Zeiten erschiene als
 * „unverändert". Deshalb werden gepaarte Signaturen zusätzlich auf gleiche Haltefolge geprüft.
 * Ein Versatz unter einer Minute bleibt prinzipbedingt unsichtbar.
 */
final class LineVersionDiffService
{
    /**
     * Ab diesem Abstand der Abfahrtszeiten gilt eine Fahrt nicht mehr als verschoben, sondern
     * als entfallen und neu. Der am Produktivbestand gemessene Extremfall liegt bei 43 Minuten
     * (20:15 → 20:58) — bei 30 Minuten wäre er zerbrochen. Gesetzt, nicht hergeleitet.
     */
    private const PAIRING_TOLERANCE_SECONDS = 3600;

    /** Zuschlag, damit bei gleichem Zeitabstand die gleich lange Fahrt gewinnt. */
    private const STOP_COUNT_PENALTY = 300;

    public function __construct(
        private readonly StopSequenceAligner $aligner,
        private readonly ConsolidatedStopNameResolver $stopNames,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function diff(LineVersion $from, LineVersion $to, bool $withStops): array
    {
        $vonFahrten = $this->trips($from);
        $nachFahrten = $this->trips($to);

        $namen = $this->stopNames->namesFor($this->stopIdsOf($vonFahrten, $nachFahrten));

        [$unveraendert, $vonRest, $nachRest, $geaendert] = $this->matchBySignature($vonFahrten, $nachFahrten);

        foreach ($this->pairRemainder($vonRest, $nachRest) as [$a, $b]) {
            $geaendert[] = [$a, $b];
            unset($vonRest[$a['id']], $nachRest[$b['id']]);
        }

        $paare = array_map(fn (array $p): array => $this->describePair($p[0], $p[1], $namen, $withStops), $geaendert);
        usort($paare, static fn (array $a, array $b): int => GtfsTime::compare(
            $a['from_trip']['departure_time'],
            $b['from_trip']['departure_time'],
        ));

        $ergebnis = [
            'from' => $this->versionInfo($from, count($vonFahrten)),
            'to' => $this->versionInfo($to, count($nachFahrten)),
            'summary' => [
                'unchanged' => count($unveraendert),
                'changed' => count($paare),
                'added' => count($nachRest),
                'removed' => count($vonRest),
                'from_trip_count' => count($vonFahrten),
                'to_trip_count' => count($nachFahrten),
            ],
            'changed' => $paare,
            'added' => $this->tripList($nachRest, $namen),
            'removed' => $this->tripList($vonRest, $namen),
        ];

        Log::info('Line version diff computed', [
            'from' => $from->id,
            'to' => $to->id,
            'line' => $from->line,
            'day_type' => $from->day_type->value,
        ] + $ergebnis['summary']);

        return $ergebnis;
    }

    /**
     * Die Zuordnung Fahrt → Fahrt zwischen zwei Versionen, ohne die Anzeige-Aufbereitung.
     *
     * {@see diff()} zählt „unverändert" nur — für die Kurs-Übernahme (KURSE §2 K4) wird aber
     * die konkrete Paarung gebraucht: Welche Fahrt der neuen Version entspricht welcher der
     * alten. Die Vergleichslogik ist dieselbe und bleibt an einer Stelle.
     *
     * @return array{pairs: array<int, array{to_trip_id: int, status: string}>, added: array<int, int>, removed: array<int, int>}
     */
    public function pairing(LineVersion $from, LineVersion $to): array
    {
        $vonFahrten = $this->trips($from);
        $nachFahrten = $this->trips($to);

        [$unveraendert, $vonRest, $nachRest, $geaendert] = $this->matchBySignature($vonFahrten, $nachFahrten);

        foreach ($this->pairRemainder($vonRest, $nachRest) as [$a, $b]) {
            $geaendert[] = [$a, $b];
            unset($vonRest[$a['id']], $nachRest[$b['id']]);
        }

        $paare = [];

        foreach ($unveraendert as [$alt, $neu]) {
            $paare[(int) $alt['id']] = ['to_trip_id' => (int) $neu['id'], 'status' => 'unchanged'];
        }

        foreach ($geaendert as [$alt, $neu]) {
            $paare[(int) $alt['id']] = ['to_trip_id' => (int) $neu['id'], 'status' => 'changed'];
        }

        return [
            'pairs' => $paare,
            'added' => array_map(intval(...), array_keys($nachRest)),
            'removed' => array_map(intval(...), array_keys($vonRest)),
        ];
    }

    /**
     * Fahrten einer Version samt Haltefolge, indiziert über die Fahrt-Id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function trips(LineVersion $version): array
    {
        $fahrten = DB::table('consolidated_trips')
            ->where('line_version_id', $version->id)
            ->orderBy('id')
            ->get(['id', 'signature', 'first_stop_id', 'last_stop_id'])
            ->keyBy('id');

        if ($fahrten->isEmpty()) {
            return [];
        }

        $folgen = [];

        foreach (array_chunk($fahrten->keys()->all(), 1000) as $teil) {
            $zeilen = DB::table('consolidated_stop_times')
                ->whereIn('consolidated_trip_id', $teil)
                ->orderBy('consolidated_trip_id')
                ->orderBy('stop_sequence')
                ->get(['consolidated_trip_id', 'stop_id', 'arrival_time', 'departure_time']);

            foreach ($zeilen as $zeile) {
                $folgen[(int) $zeile->consolidated_trip_id][] = [
                    'stop_id' => (int) $zeile->stop_id,
                    'time' => $zeile->departure_time ?? $zeile->arrival_time,
                ];
            }
        }

        $ergebnis = [];

        foreach ($fahrten as $id => $fahrt) {
            $folge = $folgen[(int) $id] ?? [];

            $ergebnis[(int) $id] = [
                'id' => (int) $id,
                'signature' => $fahrt->signature,
                'first_stop_id' => $fahrt->first_stop_id === null ? null : (int) $fahrt->first_stop_id,
                'last_stop_id' => $fahrt->last_stop_id === null ? null : (int) $fahrt->last_stop_id,
                'stops' => array_column($folge, 'stop_id'),
                'times' => array_column($folge, 'time'),
            ];
        }

        return $ergebnis;
    }

    /**
     * Multiset-Abgleich der Signaturen. Gepaarte Fahrten mit abweichender Haltefolge werden zu
     * „geändert" herabgestuft — die Signatur allein würde den Laufweg-Wechsel verschweigen.
     *
     * @param  array<int, array<string, mixed>>  $von
     * @param  array<int, array<string, mixed>>  $nach
     * @return array{0: array<int, array{0: array<string, mixed>, 1: array<string, mixed>}>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>, 3: array<int, array{0: array<string, mixed>, 1: array<string, mixed>}>}
     */
    private function matchBySignature(array $von, array $nach): array
    {
        $nachSignaturen = [];

        foreach ($nach as $fahrt) {
            $nachSignaturen[$fahrt['signature']][] = $fahrt['id'];
        }

        $unveraendert = [];
        $geaendert = [];
        $vonRest = $von;
        $nachRest = $nach;

        foreach ($von as $fahrt) {
            $kandidaten = &$nachSignaturen[$fahrt['signature']];

            if (empty($kandidaten)) {
                continue;
            }

            $partnerId = array_shift($kandidaten);
            $partner = $nach[$partnerId];

            unset($vonRest[$fahrt['id']], $nachRest[$partnerId]);

            if ($fahrt['stops'] === $partner['stops']) {
                $unveraendert[] = [$fahrt, $partner];

                continue;
            }

            $geaendert[] = [$fahrt, $partner];
        }

        return [$unveraendert, $vonRest, $nachRest, $geaendert];
    }

    /**
     * Paart die übrig gebliebenen Fahrten zu „verschoben".
     *
     * Gebündelt nach Start- und Zielhalt, darin greedy nach den geringsten Kosten. Die Sortierung
     * ist vollständig durchdefiniert, damit dieselbe Eingabe dasselbe Ergebnis liefert — greedy
     * ist nicht optimal, aber bei den real auftretenden Bündelgrößen (eins bis drei) folgenlos.
     *
     * @param  array<int, array<string, mixed>>  $von
     * @param  array<int, array<string, mixed>>  $nach
     * @return array<int, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    private function pairRemainder(array $von, array $nach): array
    {
        $kandidaten = [];

        foreach ($von as $a) {
            foreach ($nach as $b) {
                if ($a['first_stop_id'] !== $b['first_stop_id'] || $a['last_stop_id'] !== $b['last_stop_id']) {
                    continue;
                }

                $abstand = abs(($this->departure($a) ?? 0) - ($this->departure($b) ?? 0));

                if ($abstand > self::PAIRING_TOLERANCE_SECONDS) {
                    continue;
                }

                $kosten = $abstand + (count($a['stops']) === count($b['stops']) ? 0 : self::STOP_COUNT_PENALTY);
                $kandidaten[] = ['kosten' => $kosten, 'a' => $a, 'b' => $b];
            }
        }

        usort($kandidaten, static fn (array $x, array $y): int => $x['kosten'] <=> $y['kosten']
            ?: $x['a']['id'] <=> $y['a']['id']
            ?: $x['b']['id'] <=> $y['b']['id']);

        $paare = [];
        $vergeben = [];

        foreach ($kandidaten as $k) {
            if (isset($vergeben['a'.$k['a']['id']]) || isset($vergeben['b'.$k['b']['id']])) {
                continue;
            }

            $vergeben['a'.$k['a']['id']] = $vergeben['b'.$k['b']['id']] = true;
            $paare[] = [$k['a'], $k['b']];
        }

        return $paare;
    }

    /**
     * Beschreibt ein Paar: Was hat sich geändert, um wie viel, und wo genau.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  array<int, string>  $namen
     * @return array<string, mixed>
     */
    private function describePair(array $a, array $b, array $namen, bool $withStops): array
    {
        $zeilen = $this->stopDiff($a, $b, $namen);

        $deltas = array_values(array_filter(
            array_column($zeilen, 'delta_seconds'),
            static fn (?int $d): bool => $d !== null,
        ));

        $laufwegGeaendert = $a['stops'] !== $b['stops'];
        $zeitGeaendert = $deltas !== [] && max($deltas) !== 0;

        $paar = [
            'reason' => match (true) {
                $laufwegGeaendert && $zeitGeaendert => 'time_and_route',
                $laufwegGeaendert => 'route',
                default => 'time',
            },
            'shift_seconds' => $deltas[0] ?? null,
            'uniform_shift' => $deltas !== [] && count(array_unique($deltas)) === 1,
            'from_trip' => $this->tripInfo($a, $namen),
            'to_trip' => $this->tripInfo($b, $namen),
        ];

        if ($withStops) {
            $paar['stops'] = $zeilen;
        }

        return $paar;
    }

    /**
     * Halt-für-Halt-Vergleich über die ausgerichtete gemeinsame Achse.
     *
     * Bewusst nicht positionsweise: Fällt ein Halt weg, wären ab dort alle Zeilen gegeneinander
     * verschoben und jede Angabe falsch.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  array<int, string>  $namen
     * @return array<int, array<string, mixed>>
     */
    private function stopDiff(array $a, array $b, array $namen): array
    {
        $ausgerichtet = $this->aligner->align([
            ['stops' => $a['stops'], 'weight' => 1],
            ['stops' => $b['stops'], 'weight' => 1],
        ]);

        $vonZeit = [];
        $nachZeit = [];

        foreach ($ausgerichtet['mapping'][0] ?? [] as $position => $zeile) {
            $vonZeit[$zeile] = $a['times'][$position] ?? null;
        }
        foreach ($ausgerichtet['mapping'][1] ?? [] as $position => $zeile) {
            $nachZeit[$zeile] = $b['times'][$position] ?? null;
        }

        $zeilen = [];

        foreach ($ausgerichtet['axis'] as $index => $stopId) {
            $vonSek = GtfsTime::toSeconds($vonZeit[$index] ?? null);
            $nachSek = GtfsTime::toSeconds($nachZeit[$index] ?? null);

            $status = match (true) {
                $vonSek !== null && $nachSek !== null => $vonSek === $nachSek ? 'equal' : 'shifted',
                $vonSek !== null => 'only_from',
                default => 'only_to',
            };

            $zeilen[] = [
                'stop_id' => $stopId,
                'stop_name' => $namen[$stopId] ?? '—',
                'from_time' => GtfsTime::toClock($vonZeit[$index] ?? null),
                'to_time' => GtfsTime::toClock($nachZeit[$index] ?? null),
                'delta_seconds' => $vonSek !== null && $nachSek !== null ? $nachSek - $vonSek : null,
                'status' => $status,
            ];
        }

        return $zeilen;
    }

    /**
     * @param  array<string, mixed>  $fahrt
     */
    private function departure(array $fahrt): ?int
    {
        return GtfsTime::toSeconds($fahrt['times'][0] ?? null);
    }

    /**
     * @param  array<string, mixed>  $fahrt
     * @param  array<int, string>  $namen
     * @return array<string, mixed>
     */
    private function tripInfo(array $fahrt, array $namen): array
    {
        $letzte = count($fahrt['times']) - 1;

        return [
            'id' => $fahrt['id'],
            'signature' => $fahrt['signature'],
            'start_stop' => $namen[$fahrt['first_stop_id']] ?? '—',
            'end_stop' => $namen[$fahrt['last_stop_id']] ?? '—',
            'departure_time' => $fahrt['times'][0] ?? null,
            'arrival_time' => $letzte >= 0 ? $fahrt['times'][$letzte] : null,
            'stop_count' => count($fahrt['stops']),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fahrten
     * @param  array<int, string>  $namen
     * @return array<int, array<string, mixed>>
     */
    private function tripList(array $fahrten, array $namen): array
    {
        $liste = array_map(fn (array $f): array => $this->tripInfo($f, $namen), array_values($fahrten));

        usort($liste, static fn (array $a, array $b): int => GtfsTime::compare($a['departure_time'], $b['departure_time']));

        return $liste;
    }

    /**
     * Die Periode gehört mit in die Ausgabe: `version_no` zählt je Periode, „v3 gegen v1"
     * wäre ohne sie nicht einzuordnen (vgl. LineVersionDiffRequest).
     */
    private function versionInfo(LineVersion $version, int $tripCount): array
    {
        $version->loadMissing(['intervals', 'period']);

        return [
            'id' => $version->id,
            'line' => $version->line,
            'period' => $version->period === null ? null : [
                'id' => $version->period->id,
                'label' => $version->period->label,
                'valid_from' => $version->period->valid_from->toDateString(),
                'status' => $version->period->status->value,
            ],
            'day_type' => $version->day_type->value,
            'day_type_label' => $version->day_type->label(),
            'version_no' => $version->version_no,
            'fingerprint' => $version->fingerprint,
            'trip_count' => $tripCount,
            'intervals' => $version->intervals->sortBy('valid_from')->map(fn ($i): array => [
                'valid_from' => $i->valid_from->toDateString(),
                'valid_to' => $i->valid_to->toDateString(),
                'from_confirmed' => (bool) $i->from_confirmed,
                'to_confirmed' => (bool) $i->to_confirmed,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $von
     * @param  array<int, array<string, mixed>>  $nach
     * @return array<int, int>
     */
    private function stopIdsOf(array $von, array $nach): array
    {
        $ids = [];

        foreach ([$von, $nach] as $menge) {
            foreach ($menge as $fahrt) {
                foreach ($fahrt['stops'] as $stopId) {
                    $ids[$stopId] = true;
                }
            }
        }

        return array_keys($ids);
    }
}
