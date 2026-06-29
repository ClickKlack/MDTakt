<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Liefert alle Fahrten einer Linie, gruppiert nach Starthaltestelle → Zielhaltestelle.
 *
 * Start/Ziel werden über Window-Funktionen (erster/letzter Halt je Trip nach
 * stop_sequence) ermittelt — portabel (PostgreSQL & SQLite), keine verschachtelten
 * Subqueries in PHP. Die reine Gruppierung der flachen Ergebniszeilen erfolgt in PHP.
 */
final class LineTripService
{
    /**
     * @return array{
     *     line: string,
     *     trip_count: int,
     *     groups: array<int, array{
     *         start_stop: string,
     *         end_stop: string,
     *         trip_count: int,
     *         trips: array<int, array{trip_id: string, service_id: string, departure_time: string|null, arrival_time: string|null}>
     *     }>
     * }
     */
    public function groupedByStartEnd(string $line): array
    {
        // Erste Halt-Zeile (rn=1 aufsteigend) mit letzter Halt-Zeile (rn=1 absteigend) je Trip verbinden.
        $rows = DB::select(
            <<<'SQL'
            select f.trip_id, f.service_id,
                   f.stop_name      as start_stop,
                   f.departure_time as departure_time,
                   l.stop_name      as end_stop,
                   l.arrival_time   as arrival_time
            from (
                select st.trip_id, t.service_id, s.stop_name, st.departure_time,
                       row_number() over (partition by st.trip_id order by st.stop_sequence asc) as rn
                from stop_times st
                join stops s  on s.stop_id  = st.stop_id
                join trips t  on t.trip_id  = st.trip_id
                join routes r on r.route_id = t.route_id
                where r.route_short_name = ?
            ) f
            join (
                select st.trip_id, s.stop_name,
                       coalesce(st.arrival_time, st.departure_time) as arrival_time,
                       row_number() over (partition by st.trip_id order by st.stop_sequence desc) as rn
                from stop_times st
                join stops s  on s.stop_id  = st.stop_id
                join trips t  on t.trip_id  = st.trip_id
                join routes r on r.route_id = t.route_id
                where r.route_short_name = ?
            ) l on l.trip_id = f.trip_id and l.rn = 1
            where f.rn = 1
            order by start_stop, end_stop, f.departure_time
            SQL,
            [$line, $line],
        );

        $groups = [];
        foreach ($rows as $row) {
            $key = $row->start_stop.' → '.$row->end_stop;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'start_stop' => $row->start_stop,
                    'end_stop' => $row->end_stop,
                    'trip_count' => 0,
                    'trips' => [],
                ];
            }
            $groups[$key]['trip_count']++;
            $groups[$key]['trips'][] = [
                'trip_id' => $row->trip_id,
                'service_id' => $row->service_id,
                'departure_time' => $row->departure_time,
                'arrival_time' => $row->arrival_time,
            ];
        }

        // Größte Gruppen (meiste Fahrten) zuerst.
        usort($groups, static fn (array $a, array $b): int => $b['trip_count'] <=> $a['trip_count']);

        Log::debug('Line trips grouped by start/end', [
            'line' => $line,
            'group_count' => count($groups),
            'trip_count' => count($rows),
        ]);

        return [
            'line' => $line,
            'trip_count' => count($rows),
            'groups' => array_values($groups),
        ];
    }
}
