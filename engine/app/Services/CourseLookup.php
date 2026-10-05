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
     * - `seen_through`: Nicht diese Fahrt wurde gesichtet, aber eine, mit der sie über
     *   **Durchläufe** verbunden ist (KURSE §2 K10) — mit derselben Nummer. Am Tauschpunkt
     *   verlässt das Fahrzeug den Halt nicht; was davor beobachtet wurde, gilt danach weiter.
     *   Über eine Wende geht es nicht hinaus. Schwächer als `seen`, weil auch ein Durchlauf
     *   brechen kann, und eine eigene Markierung der Fahrt geht ihm vor.
     *
     * Abgelehnte Sichtungen zählen nicht. Passt eine entschiedene Sichtung nicht mehr zum Kurs
     * (später von Hand geändert), belegt sie ihn nicht — keine Markierung.
     *
     * @param  array<int, array{id: int, number: string}>  $kurse  Ergebnis von {@see forTrips()}
     * @return array<int, 'seen'|'disputed'|'seen_through'> nach `consolidated_trips.id` geschlüsselt
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

        $offen = array_values(array_diff(array_keys($kurse), array_keys($marken)));

        foreach ($this->seenThroughRuns($offen, $kurse) as $tripId) {
            $marken[$tripId] = 'seen_through';
        }

        return $marken;
    }

    /**
     * Fahrten, deren Kurs über Durchläufe hinweg gesichtet ist.
     *
     * Erst die Durchlauf-Gruppen: von den offenen Fahrten aus über `through_run`-Kanten in beide
     * Richtungen, bis nichts Neues mehr dazukommt. Dann **eine** Abfrage nach entschiedenen
     * Sichtungen in diesen Gruppen. Verglichen wird mit der Nummer der offenen Fahrt — die
     * gesichtete muss dieselbe nennen.
     *
     * @param  array<int, int>  $offen
     * @param  array<int, array{id: int, number: string}>  $kurse
     * @return array<int, int>
     */
    private function seenThroughRuns(array $offen, array $kurse): array
    {
        if ($offen === []) {
            return [];
        }

        // Union-Find über die Fahrt-Ids: Jede Durchlauf-Kante verbindet zwei Gruppen.
        $eltern = [];
        $wurzel = function (int $id) use (&$eltern, &$wurzel): int {
            $eltern[$id] ??= $id;

            return $eltern[$id] === $id ? $id : ($eltern[$id] = $wurzel($eltern[$id]));
        };

        $bekannt = array_fill_keys($offen, true);
        $rand = $offen;

        // Ein Durchlauf reiht sich selten öfter als ein paarmal; die Grenze schützt nur vor
        // einem Ring im Altbestand.
        for ($schritt = 0; $rand !== [] && $schritt < 50; $schritt++) {
            $neu = [];

            foreach (array_chunk($rand, self::CHUNK) as $teil) {
                $kanten = DB::table('trip_links')
                    ->where('through_run', true)
                    ->whereNotNull('from_trip_id')
                    ->whereNotNull('to_trip_id')
                    ->where(function ($q) use ($teil): void {
                        $q->whereIn('from_trip_id', $teil)->orWhereIn('to_trip_id', $teil);
                    })
                    ->get(['from_trip_id', 'to_trip_id']);

                foreach ($kanten as $kante) {
                    $von = (int) $kante->from_trip_id;
                    $nach = (int) $kante->to_trip_id;
                    $eltern[$wurzel($von)] = $wurzel($nach);

                    foreach ([$von, $nach] as $id) {
                        if (! isset($bekannt[$id])) {
                            $bekannt[$id] = true;
                            $neu[] = $id;
                        }
                    }
                }
            }

            $rand = $neu;
        }

        $verbunden = array_keys(array_filter(
            $bekannt,
            static fn (bool $_, int $id): bool => isset($eltern[$id]),
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($verbunden === []) {
            return [];
        }

        // Gesichtete Nummern je Gruppe.
        $gesichtet = [];

        foreach (array_chunk($verbunden, self::CHUNK) as $teil) {
            $zeilen = DB::table('sightings')
                ->whereIn('consolidated_trip_id', $teil)
                ->whereIn('match', [SightingMatch::Matched->value, SightingMatch::MatchedNextVersion->value])
                ->whereIn('status', [SightingStatus::Confirmed->value, SightingStatus::Accepted->value])
                ->select('consolidated_trip_id', 'course_number')
                ->get();

            foreach ($zeilen as $zeile) {
                $gesichtet[$wurzel((int) $zeile->consolidated_trip_id)][] = (string) $zeile->course_number;
            }
        }

        $treffer = [];

        foreach ($offen as $tripId) {
            if (! isset($eltern[$tripId])) {
                continue;
            }

            foreach ($gesichtet[$wurzel($tripId)] ?? [] as $nummer) {
                if (Course::sameNumber($kurse[$tripId]['number'], $nummer)) {
                    $treffer[] = $tripId;
                    break;
                }
            }
        }

        return $treffer;
    }
}
