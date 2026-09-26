<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SightingMatch;
use App\Enums\SightingStatus;
use App\Models\Course;
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

    /**
     * Belegt eine Sichtung den Kurs **dieser** Fahrt?
     *
     * - `seen`: Eine bestätigte oder angenommene Sichtung an genau dieser Fahrt nennt die Nummer
     *   des Kurses. Nur die gesichtete Fahrt, nicht die übrige Kette — so bleibt erkennbar, was
     *   beobachtet und was über Anschlüsse fortgeschrieben ist.
     * - `disputed`: Eine offene Sichtung an dieser Fahrt nennt eine andere Nummer. Geht vor `seen`.
     *
     * Abgelehnte Sichtungen zählen nicht. Passt eine entschiedene Sichtung nicht mehr zum Kurs
     * (später von Hand geändert), belegt sie ihn nicht — keine Markierung.
     *
     * @param  array<int, array{id: int, number: string}>  $kurse  Ergebnis von {@see forTrips()}
     * @return array<int, 'seen'|'disputed'> nach `consolidated_trips.id` geschlüsselt
     */
    public function sightingMarks(array $kurse): array
    {
        if ($kurse === []) {
            return [];
        }

        $marken = [];

        foreach (array_chunk(array_keys($kurse), self::CHUNK) as $teil) {
            $zeilen = DB::table('sightings')
                ->whereIn('consolidated_trip_id', $teil)
                ->whereIn('match', [SightingMatch::Matched->value, SightingMatch::MatchedNextVersion->value])
                ->whereIn('status', [
                    SightingStatus::Pending->value,
                    SightingStatus::Confirmed->value,
                    SightingStatus::Accepted->value,
                ])
                ->select('consolidated_trip_id', 'course_number', 'status')
                ->get();

            foreach ($zeilen as $zeile) {
                $tripId = (int) $zeile->consolidated_trip_id;
                $gleich = Course::sameNumber($kurse[$tripId]['number'], (string) $zeile->course_number);

                if ($zeile->status === SightingStatus::Pending->value) {
                    if (! $gleich) {
                        $marken[$tripId] = 'disputed';
                    }
                } elseif ($gleich && ($marken[$tripId] ?? null) === null) {
                    $marken[$tripId] = 'seen';
                }
            }
        }

        return $marken;
    }
}
