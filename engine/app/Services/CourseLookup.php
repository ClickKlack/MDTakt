<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Welcher Kurs hängt an welcher Fahrt?
 *
 * Eigene Klasse ohne Abhängigkeiten, weil drei Stellen dieselbe Auskunft brauchen — der
 * Haltestellen-Editor, die Fahrplan-Matrix und die Kurs-Pflege selbst. Als Methode an
 * {@see CourseService} ginge es nicht: Der hängt über {@see TripLinkService} an
 * {@see ConsolidatedTripInfoResolver}, und der bräuchte ihn wiederum — ein Ring.
 */
final class CourseLookup
{
    /** Fahrten je Abfrage — Kompromiss aus Roundtrips und Parametergrenze des Treibers. */
    private const CHUNK = 1000;

    /**
     * @param  array<int, int>  $tripIds
     * @return array<int, array{id: int, number: string}> nach `consolidated_trips.id` geschlüsselt
     */
    public function forTrips(array $tripIds): array
    {
        $eindeutig = array_values(array_unique(array_filter($tripIds)));

        if ($eindeutig === []) {
            return [];
        }

        $kurse = [];

        foreach (array_chunk($eindeutig, self::CHUNK) as $teil) {
            $zeilen = DB::table('course_trips as k')
                ->join('courses as c', 'c.id', '=', 'k.course_id')
                ->whereIn('k.consolidated_trip_id', $teil)
                ->select('k.consolidated_trip_id', 'c.id', 'c.number')
                ->get();

            foreach ($zeilen as $zeile) {
                $kurse[(int) $zeile->consolidated_trip_id] = [
                    'id' => (int) $zeile->id,
                    'number' => (string) $zeile->number,
                ];
            }
        }

        return $kurse;
    }
}
