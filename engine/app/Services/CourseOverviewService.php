<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use App\Models\Course;
use App\Models\SchedulePeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Die Umläufe einer Linie im Ganzen: je Kurs seine Fahrten in Fahrreihenfolge, die Stellen, an
 * denen die Kette reißt, und die Fahrten dieser Linie, die noch zu keinem Umlauf gehören.
 *
 * **Warum „Lücke" und „nicht verknüpft" zwei verschiedene Dinge sind:** Ein Umlauf muss keine
 * durchgehende Kette sein. Löst man einen Anschluss in der Mitte, bleibt der Kurs an beiden
 * Teilen hängen — gewollt, weil die Zuordnung bewahrt und nicht geraten wird. Die Übersicht
 * muss deshalb beides zeigen: den zeitlichen Abstand zur Vorfahrt und ob dort überhaupt ein
 * Anschluss steht. Ein großer Abstand *mit* Anschluss ist eine lange Wende; ein Abstand *ohne*
 * Anschluss ist eine gerissene Kette.
 */
final class CourseOverviewService
{
    public function __construct(
        private readonly ConsolidatedTripInfoResolver $tripInfo,
        private readonly ConsolidatedTripStopsResolver $tripStops,
    ) {}

    /**
     * `$withStops` hängt jeder Fahrt ihre vollständige Haltefolge an — die Grundlage des
     * Fahrtenbuchs, das den Betriebstag eines Fahrzeugs Halt für Halt durchläuft.
     *
     * Bewusst abschaltbar und in der Vorgabe aus: Eine Linie mit acht Umläufen à zwanzig Fahrten
     * bringt mehrere tausend Haltezeilen mit. Wer nur die Ketten sehen will, soll sie nicht
     * übertragen müssen.
     *
     * @return array<string, mixed>
     */
    public function forLine(string $line, SchedulePeriod $period, FahrplanTyp $typ, bool $withStops = false): array
    {
        $kurse = Course::query()
            ->where('period_id', $period->id)
            ->where('day_type', $typ->value)
            ->get();

        $zuordnungen = $this->assignments($kurse->pluck('id')->all());
        $alleTripIds = array_merge(...array_values($zuordnungen)) ?: [];

        $info = $this->tripInfo->forIds($alleTripIds);
        $anschluesse = $this->links($alleTripIds);

        // Einmal für alle Fahrten aller Umläufe — nicht je Kurs, sonst fragte dieselbe Linie
        // achtmal dieselbe Tabelle ab.
        $halte = $withStops ? $this->tripStops->forTrips($alleTripIds) : [];

        // Eine Dublette ist dieselbe Nummer auf **überschneidenden Linien** (KURSE §2 K3):
        // Die „2" der Linie 8 ist ein anderer Umlauf als die „2" der Linie 6 und kein Fehler.
        // Erst wenn sich die Linien berühren, widersprechen sich zwei Nummern.
        $linienJeKurs = [];

        foreach ($zuordnungen as $kursId => $tripIds) {
            $linienJeKurs[$kursId] = array_values(array_unique(array_filter(array_map(
                static fn (int $id): ?string => $info[$id]['line'] ?? null,
                $tripIds,
            ))));
        }

        $ergebnis = [];

        foreach ($kurse as $kurs) {
            $tripIds = $zuordnungen[$kurs->id] ?? [];
            $fahrten = array_values(array_filter(array_map(
                static fn (int $id): ?array => $info[$id] ?? null,
                $tripIds,
            )));

            $linien = array_values(array_unique(array_column($fahrten, 'line')));

            // Nur Umläufe, die diese Linie berühren — ein Umlauf kann mehrere umfassen und
            // erscheint dann bei jeder von ihnen.
            if (! in_array($line, $linien, true)) {
                continue;
            }

            usort($fahrten, static fn (array $a, array $b): int => $a['departure_sort'] <=> $b['departure_sort']);

            $ergebnis[] = [
                'id' => $kurs->id,
                'number' => $kurs->number,
                'note' => $kurs->note,
                'duplicate' => $this->hasOverlappingTwin($kurs, $kurse, $linienJeKurs),
                'trip_count' => count($fahrten),
                'lines' => $this->sortiert($linien),
                'first_departure' => $fahrten === [] ? null : $fahrten[0]['departure_time'],
                'last_arrival' => $fahrten === [] ? null : $fahrten[count($fahrten) - 1]['arrival_time'],
                'trips' => $this->withChainInfo($fahrten, $anschluesse, $halte),
                'breaks' => $this->countBreaks($fahrten, $anschluesse),
            ];
        }

        usort($ergebnis, static fn (array $a, array $b): int => strnatcmp($a['number'], $b['number']));

        $offen = $this->unassigned($line, $period, $typ);

        return [
            'line' => $line,
            'period' => ['id' => $period->id, 'label' => $period->label, 'status' => $period->status->value],
            'day_type' => $typ->value,
            'day_type_label' => $typ->label(),
            'courses' => $ergebnis,
            'unassigned' => $offen,
            'summary' => [
                'courses' => count($ergebnis),
                'assigned_trips' => array_sum(array_column($ergebnis, 'trip_count')),
                'unassigned_trips' => count($offen),
                'breaks' => array_sum(array_column($ergebnis, 'breaks')),
            ],
        ];
    }

    /**
     * Trägt ein anderer Umlauf dieselbe Nummer auf einer gemeinsamen Linie?
     *
     * Ein Kurs ohne Fahrten ist an keine Linie gebunden und kollidiert deshalb mit jeder —
     * eine leere Hülle mit vergebener Nummer ist genau das, was hier auffallen soll.
     *
     * @param  Collection<int, Course>  $alle
     * @param  array<int, array<int, string>>  $linienJeKurs
     */
    private function hasOverlappingTwin(Course $kurs, $alle, array $linienJeKurs): bool
    {
        foreach ($alle as $anderer) {
            if ($anderer->id === $kurs->id || $anderer->number !== $kurs->number) {
                continue;
            }

            $a = $linienJeKurs[$kurs->id] ?? [];
            $b = $linienJeKurs[$anderer->id] ?? [];

            if ($a === [] || $b === [] || array_intersect($a, $b) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ergänzt je Fahrt den Abstand zur Vorfahrt und ob dort ein Anschluss steht.
     *
     * @param  array<int, array<string, mixed>>  $fahrten
     * @param  array<string, bool>  $anschluesse
     * @param  array<int, array<int, array<string, mixed>>>  $halte  leer, wenn nicht angefordert
     * @return array<int, array<string, mixed>>
     */
    private function withChainInfo(array $fahrten, array $anschluesse, array $halte = []): array
    {
        $ergebnis = [];

        foreach ($fahrten as $i => $fahrt) {
            $vorher = $i === 0 ? null : $fahrten[$i - 1];

            $verknuepft = $vorher !== null && isset($anschluesse[$vorher['id'].'>'.$fahrt['id']]);

            $abstand = null;

            if ($vorher !== null
                && $vorher['arrival_sort'] !== PHP_INT_MAX
                && $fahrt['departure_sort'] !== PHP_INT_MAX
            ) {
                $abstand = $fahrt['departure_sort'] - $vorher['arrival_sort'];
            }

            $ergebnis[] = $fahrt + [
                'gap_before_seconds' => $abstand,
                'linked_to_previous' => $verknuepft,
            ] + ($halte === [] ? [] : ['stops' => $halte[$fahrt['id']] ?? []]);
        }

        return $ergebnis;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fahrten
     * @param  array<string, bool>  $anschluesse
     */
    private function countBreaks(array $fahrten, array $anschluesse): int
    {
        $risse = 0;

        for ($i = 1; $i < count($fahrten); $i++) {
            if (! isset($anschluesse[$fahrten[$i - 1]['id'].'>'.$fahrten[$i]['id']])) {
                $risse++;
            }
        }

        return $risse;
    }

    /**
     * Fahrten dieser Linie im Strang, die zu keinem Umlauf gehören.
     *
     * @return array<int, array<string, mixed>>
     */
    private function unassigned(string $line, SchedulePeriod $period, FahrplanTyp $typ): array
    {
        $ids = DB::table('consolidated_trips as ct')
            ->join('line_versions as lv', 'lv.id', '=', 'ct.line_version_id')
            ->leftJoin('course_trips as k', 'k.consolidated_trip_id', '=', 'ct.id')
            ->where('lv.period_id', $period->id)
            ->where('lv.day_type', $typ->value)
            ->where('lv.line', $line)
            ->whereNull('k.id')
            ->pluck('ct.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $fahrten = array_values($this->tripInfo->forIds($ids));

        usort($fahrten, static fn (array $a, array $b): int => $a['departure_sort'] <=> $b['departure_sort']);

        return $fahrten;
    }

    /**
     * @param  array<int, int>  $courseIds
     * @return array<int, array<int, int>> course_id => Fahrt-Ids
     */
    private function assignments(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $zeilen = DB::table('course_trips')
            ->whereIn('course_id', $courseIds)
            ->get(['course_id', 'consolidated_trip_id']);

        $ergebnis = [];

        foreach ($zeilen as $zeile) {
            $ergebnis[(int) $zeile->course_id][] = (int) $zeile->consolidated_trip_id;
        }

        return $ergebnis;
    }

    /**
     * Bestehende Anschlüsse als Nachschlagewerk „A>B".
     *
     * @param  array<int, int>  $tripIds
     * @return array<string, bool>
     */
    private function links(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        return DB::table('trip_links')
            ->whereIn('from_trip_id', $tripIds)
            ->whereNotNull('to_trip_id')
            ->get(['from_trip_id', 'to_trip_id'])
            ->mapWithKeys(static fn (object $l): array => [$l->from_trip_id.'>'.$l->to_trip_id => true])
            ->all();
    }

    /**
     * @param  array<int, string>  $linien
     * @return array<int, string>
     */
    private function sortiert(array $linien): array
    {
        sort($linien, SORT_NATURAL);

        return $linien;
    }
}
