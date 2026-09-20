<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Erste Abfahrt und letzte Ankunft je konsolidierter Fahrt.
 *
 * `consolidated_trips` speichert diese beiden Zeiten **nicht** — sie sind Ableitungen aus
 * `consolidated_stop_times` über die kleinste und größte `stop_sequence`. Das ist Absicht: Die
 * Haltzeiten sind die Wahrheit, eine mitgeführte Kopie wäre eine zweite.
 *
 * Herausgelöst aus {@see ConsolidatedScheduleService}, weil inzwischen mehrere Aufrufer dieselbe
 * Ableitung brauchen (Fahrplan-Ansicht, Haltestellen-Editor, Kursübersicht). Sie dreimal zu
 * kopieren hieße, dreimal dieselbe Grenzfall-Entscheidung zu treffen.
 *
 * **Die Zeiten sind GTFS-Wallclock**, nicht Uhrzeiten: `25:10:00` gehört zum Betriebstag des
 * Vortags. Wer sie vergleicht, nimmt {@see \App\Support\GtfsTime}, nie den String-Vergleich.
 */
final class ConsolidatedTripTimeResolver
{
    /** Fahrten je Abfrage — Kompromiss aus Roundtrips und Parametergrenze des Treibers. */
    private const CHUNK = 1000;

    /**
     * @param  array<int, int>  $tripIds
     * @return array<int, array{departure: string|null, arrival: string|null}>
     */
    public function endpoints(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $zeiten = [];

        foreach (array_chunk($tripIds, self::CHUNK) as $teil) {
            $grenzen = DB::table('consolidated_stop_times')
                ->whereIn('consolidated_trip_id', $teil)
                ->select(
                    'consolidated_trip_id',
                    DB::raw('min(stop_sequence) as first_seq'),
                    DB::raw('max(stop_sequence) as last_seq'),
                )
                ->groupBy('consolidated_trip_id')
                ->get()
                ->keyBy('consolidated_trip_id');

            $details = DB::table('consolidated_stop_times')
                ->whereIn('consolidated_trip_id', $teil)
                ->select('consolidated_trip_id', 'stop_sequence', 'departure_time', 'arrival_time')
                ->get();

            foreach ($details as $zeile) {
                $grenze = $grenzen->get($zeile->consolidated_trip_id);

                if ($grenze === null) {
                    continue;
                }

                if ((int) $zeile->stop_sequence === (int) $grenze->first_seq) {
                    $zeiten[$zeile->consolidated_trip_id]['departure'] = $zeile->departure_time;
                }
                if ((int) $zeile->stop_sequence === (int) $grenze->last_seq) {
                    $zeiten[$zeile->consolidated_trip_id]['arrival'] = $zeile->arrival_time;
                }
            }
        }

        return $zeiten;
    }
}
