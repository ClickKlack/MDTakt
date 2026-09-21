<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Die vollständige Haltefolge einer Fahrt samt Zeiten — das, was die Eckzeiten aus
 * {@see ConsolidatedTripTimeResolver} bewusst weglassen.
 *
 * Gebraucht wird das dort, wo nicht die Fahrt als Ganzes zählt, sondern ihr Verlauf: im
 * Fahrtenbuch eines Umlaufs, das den Betriebstag eines Fahrzeugs Halt für Halt durchläuft.
 *
 * **Zeiten bleiben GTFS-Wallclock.** `25:10:00` ist gültig und gewollt — die Fahrt gehört zum
 * Betriebstag des Vortags. Formatiert wird erst in der Anzeige; hier wird nichts umgerechnet und
 * nichts abgeschnitten.
 */
final class ConsolidatedTripStopsResolver
{
    public function __construct(private readonly ConsolidatedStopNameResolver $stopNames) {}

    /**
     * @param  array<int, int>  $tripIds
     * @return array<int, array<int, array{stop_id: int, stop_name: string, arrival_time: string|null, departure_time: string|null}>>
     *                                                                                                                                nach `consolidated_trips.id` geschlüsselt, je Fahrt in Sequenzreihenfolge
     */
    public function forTrips(array $tripIds): array
    {
        $eindeutig = array_values(array_unique(array_filter($tripIds)));

        if ($eindeutig === []) {
            return [];
        }

        $zeilen = [];

        foreach (array_chunk($eindeutig, 1000) as $teil) {
            $treffer = DB::table('consolidated_stop_times')
                ->whereIn('consolidated_trip_id', $teil)
                ->orderBy('consolidated_trip_id')
                ->orderBy('stop_sequence')
                ->get(['consolidated_trip_id', 'stop_id', 'arrival_time', 'departure_time']);

            foreach ($treffer as $zeile) {
                $zeilen[] = $zeile;
            }
        }

        if ($zeilen === []) {
            return [];
        }

        $namen = $this->stopNames->namesFor(
            array_values(array_unique(array_map(static fn (object $z): int => (int) $z->stop_id, $zeilen)))
        );

        $ergebnis = [];

        foreach ($zeilen as $zeile) {
            $ergebnis[(int) $zeile->consolidated_trip_id][] = [
                'stop_id' => (int) $zeile->stop_id,
                'stop_name' => $namen[$zeile->stop_id] ?? '—',
                'arrival_time' => $zeile->arrival_time,
                'departure_time' => $zeile->departure_time,
            ];
        }

        return $ergebnis;
    }
}
