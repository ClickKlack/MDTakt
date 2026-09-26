<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RouteType;
use App\Models\LineVersion;
use App\Support\GtfsTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stellt den Fahrplan einer Linien-Version als Matrix zusammen: Halte als Zeilen, Fahrten als
 * Spalten — die Form, in der ein Fahrplan seit jeher gelesen wird.
 *
 * Gruppiert wird nach Richtung (erster und letzter Halt). Innerhalb einer Richtung können die
 * Fahrten verschiedene Laufwege haben (Kurzläufer, Umleitungen); sie werden über
 * {@see StopSequenceAligner} auf eine gemeinsame Zeilenachse gebracht, statt die Richtung in
 * mehrere Tabellen zu zerlegen.
 */
final class TimetableService
{
    /**
     * Ab diesem Verhältnis von Achsenlänge zur längsten Einzelvariante gilt die Ausrichtung als
     * fragwürdig. Am Produktivbestand wird der Wert nirgends erreicht (schlechtester Fall 1,29
     * bei 965 Richtungen) — er ist eine Reißleine, keine Erwartung.
     */
    private const ALIGNMENT_WARNING_RATIO = 1.5;

    public function __construct(
        private readonly StopSequenceAligner $aligner,
        private readonly ConsolidatedStopNameResolver $stopNames,
        private readonly OperatingDayResolver $operatingDay,
        private readonly CourseLookup $courses,
        private readonly SightingLookup $sightings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forVersion(LineVersion $version): array
    {
        $version->loadMissing(['period', 'intervals']);

        $fahrten = DB::table('consolidated_trips')
            ->where('line_version_id', $version->id)
            ->orderBy('id')
            ->get(['id', 'signature', 'route_type', 'first_stop_id', 'last_stop_id']);

        $haltefolgen = $this->stopSequences($fahrten->pluck('id')->all());
        $richtungen = $this->buildDirections($fahrten, $haltefolgen, $version->line);

        Log::debug('Timetable built', [
            'line_version_id' => $version->id,
            'line' => $version->line,
            'day_type' => $version->day_type->value,
            'directions' => count($richtungen),
            'trips' => $fahrten->count(),
        ]);

        return [
            'line_version' => [
                'id' => $version->id,
                'line' => $version->line,
                'day_type' => $version->day_type->value,
                'day_type_label' => $version->day_type->label(),
                'version_no' => $version->version_no,
                'fingerprint' => $version->fingerprint,
                'trip_count' => $fahrten->count(),
                'intervals' => $version->intervals
                    ->sortBy('valid_from')
                    ->map(fn ($i): array => [
                        'valid_from' => $i->valid_from->toDateString(),
                        'valid_to' => $i->valid_to->toDateString(),
                        'from_confirmed' => (bool) $i->from_confirmed,
                        'to_confirmed' => (bool) $i->to_confirmed,
                    ])->values()->all(),
            ],
            'period' => $version->period === null ? null : [
                'id' => $version->period->id,
                'label' => $version->period->label,
                'status' => $version->period->status->value,
            ],
            'directions' => $richtungen,
        ];
    }

    /**
     * Haltefolge und Zeiten je Fahrt, in Sequenzreihenfolge.
     *
     * @param  array<int, int>  $tripIds
     * @return array<int, array<int, array{stop_id: int, arrival: string|null, departure: string|null}>>
     */
    private function stopSequences(array $tripIds): array
    {
        $folgen = [];

        foreach (array_chunk($tripIds, 1000) as $teil) {
            $zeilen = DB::table('consolidated_stop_times')
                ->whereIn('consolidated_trip_id', $teil)
                ->orderBy('consolidated_trip_id')
                ->orderBy('stop_sequence')
                ->get(['consolidated_trip_id', 'stop_id', 'arrival_time', 'departure_time']);

            foreach ($zeilen as $zeile) {
                $folgen[(int) $zeile->consolidated_trip_id][] = [
                    'stop_id' => (int) $zeile->stop_id,
                    'arrival' => $zeile->arrival_time,
                    'departure' => $zeile->departure_time,
                ];
            }
        }

        return $folgen;
    }

    /**
     * @param  Collection<int, object>  $fahrten
     * @param  array<int, array<int, array{stop_id: int, arrival: string|null, departure: string|null}>>  $haltefolgen
     * @return array<int, array<string, mixed>>
     */
    private function buildDirections(Collection $fahrten, array $haltefolgen, string $line): array
    {
        $namen = $this->stopNames->namesFor($this->allStopIds($haltefolgen));
        $richtungen = [];

        foreach ($fahrten->groupBy(fn (object $f): string => $f->first_stop_id.'>'.$f->last_stop_id) as $key => $gruppe) {
            $richtung = $this->buildDirection((string) $key, $gruppe, $haltefolgen, $namen, $line);

            if ($richtung !== null) {
                $richtungen[] = $richtung;
            }
        }

        // Die meistbefahrene Richtung zuerst — sie ist es, die man sehen will.
        usort($richtungen, static fn (array $a, array $b): int => $b['trip_count'] <=> $a['trip_count']
            ?: strcmp($a['key'], $b['key']));

        return $richtungen;
    }

    /**
     * @param  Collection<int, object>  $gruppe
     * @param  array<int, array<int, array{stop_id: int, arrival: string|null, departure: string|null}>>  $haltefolgen
     * @param  array<int, string>  $namen
     * @return array<string, mixed>|null
     */
    private function buildDirection(string $key, Collection $gruppe, array $haltefolgen, array $namen, string $line): ?array
    {
        // Gleiche Haltefolgen zu Varianten bündeln — der Ausrichter arbeitet auf Varianten,
        // nicht auf Fahrten. Bei 94 Fahrten mit identischem Laufweg spart das die Arbeit.
        $varianten = [];
        $variantePerFahrt = [];

        foreach ($gruppe as $fahrt) {
            $folge = $haltefolgen[(int) $fahrt->id] ?? [];

            if ($folge === []) {
                continue;
            }

            $stops = array_column($folge, 'stop_id');
            $schluessel = implode(',', $stops);

            if (! isset($varianten[$schluessel])) {
                $varianten[$schluessel] = ['stops' => $stops, 'weight' => 0];
            }

            $varianten[$schluessel]['weight']++;
            $variantePerFahrt[(int) $fahrt->id] = $schluessel;
        }

        if ($varianten === []) {
            return null;
        }

        $schluessel = array_keys($varianten);
        $ausgerichtet = $this->aligner->align(array_values($varianten));
        $achse = $ausgerichtet['axis'];
        $zuordnung = [];

        foreach ($schluessel as $index => $wert) {
            $zuordnung[$wert] = $ausgerichtet['mapping'][$index];
        }

        $laengste = max(array_map(static fn (array $v): int => count($v['stops']), $varianten));

        return [
            'key' => $key,
            'start_stop' => $namen[$achse[0]] ?? '—',
            'end_stop' => $namen[$achse[count($achse) - 1]] ?? '—',
            'trip_count' => count($variantePerFahrt),
            'variant_count' => count($varianten),
            'alignment_warning' => $laengste > 0 && count($achse) > $laengste * self::ALIGNMENT_WARNING_RATIO,
            'rows' => $this->buildRows($achse, $namen),
            'trips' => $this->buildTrips($gruppe, $haltefolgen, $variantePerFahrt, $zuordnung, count($achse), $line),
        ];
    }

    /**
     * @param  array<int, int>  $achse
     * @param  array<int, string>  $namen
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(array $achse, array $namen): array
    {
        $gesehen = [];
        $zeilen = [];

        foreach ($achse as $position => $stopId) {
            // Wird ein Halt auf dem Laufweg erneut berührt, bekommt er eine eigene Zeile.
            // repeat_index macht das in der Anzeige kenntlich, statt zwei gleiche Zeilen zu zeigen.
            $gesehen[$stopId] = ($gesehen[$stopId] ?? -1) + 1;

            $zeilen[] = [
                'position' => $position,
                'stop_id' => $stopId,
                'stop_name' => $namen[$stopId] ?? '—',
                'repeat_index' => $gesehen[$stopId],
            ];
        }

        return $zeilen;
    }

    /**
     * @param  Collection<int, object>  $gruppe
     * @param  array<int, array<int, array{stop_id: int, arrival: string|null, departure: string|null}>>  $haltefolgen
     * @param  array<int, string>  $variantePerFahrt
     * @param  array<string, array<int, int>>  $zuordnung
     * @return array<int, array<string, mixed>>
     */
    private function buildTrips(
        Collection $gruppe,
        array $haltefolgen,
        array $variantePerFahrt,
        array $zuordnung,
        int $zeilenzahl,
        string $line,
    ): array {
        $spalten = [];

        // Der Kurs sagt, zu welchem Umlauf eine Fahrt gehoert — die Auskunft, wegen der
        // I-13 (D) offen war. Er haengt an der Kette, nicht an der Fahrt (KURSE §2 K2).
        $kurse = $this->courses->forTrips($gruppe->pluck('id')->map(static fn ($x): int => (int) $x)->all());
        $belege = $this->courses->sightingMarks($kurse);

        // Offene Sichtungen aus MDKursTracker: Im Fahrplan lässt sich am besten beurteilen, ob
        // eine gesichtete Nummer zur Fahrt passt — Nachbarspalten und Kette stehen daneben.
        $sichtungen = $this->sightings->pendingForTrips($gruppe->pluck('id')->map(static fn ($x): int => (int) $x)->all(), $line);

        foreach ($gruppe as $fahrt) {
            $id = (int) $fahrt->id;

            if (! isset($variantePerFahrt[$id])) {
                continue;
            }

            $folge = $haltefolgen[$id];
            $zeilen = $zuordnung[$variantePerFahrt[$id]];
            $zellen = array_fill(0, $zeilenzahl, null);

            foreach ($folge as $position => $halt) {
                $zeit = $halt['departure'] ?? $halt['arrival'];
                $zellen[$zeilen[$position]] = GtfsTime::toClock($zeit);
            }

            $erste = $folge[0];
            $letzte = $folge[count($folge) - 1];

            $spalten[] = [
                'id' => $id,
                'signature' => $fahrt->signature,
                'mode' => RouteType::modeFor((int) $fahrt->route_type),
                'departure_time' => $erste['departure'] ?? $erste['arrival'],
                'arrival_time' => $letzte['arrival'] ?? $letzte['departure'],
                'course' => isset($kurse[$id]) ? [
                    'id' => $kurse[$id]['id'],
                    'number' => $kurse[$id]['number'],
                    // Der Linien-Praefix ist reine Anzeige: dieselbe Kette heisst auf der 1
                    // "1/03" und nach dem Uebergang "13/03" (KURSE §2 K1).
                    'display' => $line.'/'.$kurse[$id]['number'],
                    'sighting' => $belege[$id] ?? null,
                ] : null,
                'sightings' => $sichtungen[$id] ?? [],
                'cells' => $zellen,
            ];
        }

        // Nach der ersten belegten Zelle sortieren, nicht nach der Abfahrt am eigenen Starthalt:
        // Ein Kurzläufer, der erst ab Zeile 5 fährt, gehört an seine zeitliche Stelle in der
        // Tabelle — sonst stünde er trotz später Abfahrt ganz vorn.
        //
        // Sortiert wird entlang des **Betriebstags**, nicht der Uhr: Auf der N1 fährt 22:49
        // vor 00:19, obwohl 00:19 als Uhrzeit kleiner ist. Ohne das stünde die halbe Nacht
        // am Tabellenanfang.
        usort($spalten, function (array $a, array $b) use ($line): int {
            return $this->operatingDay->sortKey($line, $this->firstCell($a['cells']))
                <=> $this->operatingDay->sortKey($line, $this->firstCell($b['cells']))
                ?: $a['id'] <=> $b['id'];
        });

        return $spalten;
    }

    /**
     * @param  array<int, string|null>  $zellen
     */
    private function firstCell(array $zellen): ?string
    {
        foreach ($zellen as $zelle) {
            if ($zelle !== null) {
                return $zelle;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<int, array{stop_id: int, arrival: string|null, departure: string|null}>>  $haltefolgen
     * @return array<int, int>
     */
    private function allStopIds(array $haltefolgen): array
    {
        $ids = [];

        foreach ($haltefolgen as $folge) {
            foreach ($folge as $halt) {
                $ids[$halt['stop_id']] = true;
            }
        }

        return array_keys($ids);
    }
}
