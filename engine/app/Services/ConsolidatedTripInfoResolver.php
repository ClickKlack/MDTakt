<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RouteType;
use Illuminate\Support\Facades\DB;

/**
 * Die Kurzbeschreibung einer konsolidierten Fahrt, wie sie die Umlauf-Pflege überall braucht:
 * Linie, Verkehrsmittel, Version, Start- und Zielhalt, Abfahrt und Ankunft.
 *
 * Eigener Dienst, weil drei Aufrufer dieselbe Form erwarten — der Haltestellen-Editor für
 * beide Spalten, derselbe für die Anschlusspartner, und die Antwort auf eine neu angelegte
 * Verknüpfung. Dreimal kopiert wäre es dreimal dieselbe Entscheidung über Namensauflösung und
 * Zeitableitung.
 */
final class ConsolidatedTripInfoResolver
{
    public function __construct(
        private readonly ConsolidatedStopNameResolver $stopNames,
        private readonly ConsolidatedTripTimeResolver $tripTimes,
        private readonly OperatingDayResolver $operatingDay,
    ) {}

    /**
     * Der Kurs je Fahrt, sofern einer vergeben ist.
     *
     * @param  array<int, int>  $tripIds
     * @return array<int, object>
     */
    private function coursesFor(array $tripIds): array
    {
        return DB::table('course_trips as k')
            ->join('courses as c', 'c.id', '=', 'k.course_id')
            ->whereIn('k.consolidated_trip_id', $tripIds)
            ->select('k.consolidated_trip_id', 'c.id', 'c.number')
            ->get()
            ->keyBy('consolidated_trip_id')
            ->all();
    }

    /**
     * @param  array<int, int>  $tripIds
     * @return array<int, array<string, mixed>> nach `consolidated_trips.id` geschlüsselt
     */
    public function forIds(array $tripIds): array
    {
        $eindeutig = array_values(array_unique(array_filter($tripIds)));

        if ($eindeutig === []) {
            return [];
        }

        $fahrten = DB::table('consolidated_trips as ct')
            ->join('line_versions as lv', 'lv.id', '=', 'ct.line_version_id')
            ->whereIn('ct.id', $eindeutig)
            ->select(
                'ct.id', 'ct.route_type', 'ct.first_stop_id', 'ct.last_stop_id',
                'lv.id as line_version_id', 'lv.line', 'lv.version_no',
            )
            ->get();

        if ($fahrten->isEmpty()) {
            return [];
        }

        $zeiten = $this->tripTimes->endpoints(
            $fahrten->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );

        $namen = $this->stopNames->namesFor(
            $fahrten
                ->flatMap(static fn (object $f): array => [(int) $f->first_stop_id, (int) $f->last_stop_id])
                ->all()
        );

        $kurse = $this->coursesFor($eindeutig);

        $ergebnis = [];

        foreach ($fahrten as $f) {
            $id = (int) $f->id;
            $kurs = $kurse[$id] ?? null;

            $ergebnis[$id] = [
                'id' => $id,
                'line' => $f->line,
                'mode' => RouteType::modeFor((int) $f->route_type),
                'version_no' => (int) $f->version_no,
                'line_version_id' => (int) $f->line_version_id,
                'start_stop' => $namen[$f->first_stop_id] ?? null,
                'end_stop' => $namen[$f->last_stop_id] ?? null,
                'departure_time' => $zeiten[$id]['departure'] ?? null,
                'arrival_time' => $zeiten[$id]['arrival'] ?? null,
                // Sortierschlüssel entlang des Betriebstags: Auf der N1 fährt 22:49 vor
                // 00:19. Das Frontend sortiert danach, statt die Grenzen-Regel zu doppeln.
                'departure_sort' => $this->operatingDay->sortKey($f->line, $zeiten[$id]['departure'] ?? null),
                'arrival_sort' => $this->operatingDay->sortKey($f->line, $zeiten[$id]['arrival'] ?? null),
                'course' => $kurs === null ? null : [
                    'id' => (int) $kurs->id,
                    'number' => $kurs->number,
                    // Die Nummer gehört dem Umlauf, der Linien-Präfix ist reine Anzeige:
                    // Dieselbe Kette heißt auf der 1 „1/03" und nach dem Übergang „13/03"
                    // (KURSE §2 K1).
                    'display' => $f->line.'/'.$kurs->number,
                ],
            ];
        }

        return $ergebnis;
    }
}
