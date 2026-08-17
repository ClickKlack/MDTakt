<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use App\Enums\RouteType;
use App\Models\CalendarDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Liefert alle Fahrten einer Linie, gruppiert nach Starthaltestelle → Zielhaltestelle.
 *
 * Start/Ziel werden über Window-Funktionen (erster/letzter Halt je Trip nach
 * stop_sequence) ermittelt — portabel (PostgreSQL & SQLite), keine verschachtelten
 * Subqueries in PHP. Die reine Gruppierung der flachen Ergebniszeilen erfolgt in PHP.
 *
 * Ohne Fahrplantyp-Filter enthält das Ergebnis alle Betriebstage nebeneinander —
 * dieselbe Abfahrtszeit erscheint dann mehrfach (z. B. je einmal im Werktags- und im
 * Sonntagsfahrplan). Das Verkehrsmuster je Fahrt schlüsselt das auf, `$dayType` filtert.
 */
final class LineTripService
{
    /** Wochentage in Kalenderreihenfolge — Spalte in `calendar` => Kürzel. */
    private const WEEKDAYS = [
        'monday' => 'Mo',
        'tuesday' => 'Di',
        'wednesday' => 'Mi',
        'thursday' => 'Do',
        'friday' => 'Fr',
        'saturday' => 'Sa',
        'sunday' => 'So',
    ];

    public function __construct(private readonly FahrplanTypDayResolver $dayResolver) {}

    /**
     * @return array{
     *     line: string,
     *     trip_count: int,
     *     day_type: string|null,
     *     day_type_label: string|null,
     *     reference_date: string|null,
     *     modes: array<int, string>,
     *     groups: array<int, array{
     *         start_stop: string,
     *         end_stop: string,
     *         trip_count: int,
     *         trips: array<int, array{trip_id: string, service_id: string, departure_time: string|null, arrival_time: string|null, day_pattern: string|null, service_dates: array<int, string>, mode: string}>
     *     }>
     * }
     */
    public function groupedByStartEnd(string $line, ?FahrplanTyp $dayType = null): array
    {
        $referenceDate = null;
        $serviceIds = null;

        if ($dayType !== null) {
            $resolved = $this->dayResolver->resolve($dayType);

            // Kein Tag dieses Typs im Feed → leeres Ergebnis, kein Fehler (FAHRPLANPERIODEN §8).
            if ($resolved === null) {
                return $this->emptyResult($line, $dayType);
            }

            $referenceDate = $resolved['date']->toDateString();
            $serviceIds = $resolved['service_ids'];

            // Stichtag ohne Betrieb kann nicht auftreten (resolve() prüft das), aber ein
            // leerer IN-Filter wäre stilles Fehlverhalten statt eines leeren Ergebnisses.
            if ($serviceIds === []) {
                return $this->emptyResult($line, $dayType, $referenceDate);
            }
        }

        $rows = $this->fetchRows($line, $serviceIds);

        // Services ohne Wochenmuster verkehren nur an Einzelterminen aus calendar_dates.
        $einzeltermine = $this->serviceDates(
            array_values(array_unique(array_map(
                static fn (object $row): string => $row->service_id,
                array_filter($rows, fn (object $row): bool => $this->dayPattern($row) === null),
            )))
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
                'day_pattern' => $this->dayPattern($row),
                'service_dates' => $einzeltermine[$row->service_id] ?? [],
                'mode' => RouteType::modeFor((int) $row->route_type),
            ];
        }

        // Eine Linienbezeichnung kann auf mehrere GTFS-Routen zeigen — etwa wenn ein
        // Schienenersatzverkehr dieselbe Nummer als Bus führt (N2 im Feed 08/2026).
        // Erst dann ist das Verkehrsmittel je Fahrt eine nötige Unterscheidung.
        $modes = array_values(array_unique(array_map(
            static fn (object $row): string => RouteType::modeFor((int) $row->route_type),
            $rows,
        )));
        sort($modes);

        // Größte Gruppen (meiste Fahrten) zuerst.
        usort($groups, static fn (array $a, array $b): int => $b['trip_count'] <=> $a['trip_count']);

        Log::debug('Line trips grouped by start/end', [
            'line' => $line,
            'day_type' => $dayType?->value,
            'reference_date' => $referenceDate,
            'modes' => $modes,
            'group_count' => count($groups),
            'trip_count' => count($rows),
        ]);

        return [
            'line' => $line,
            'trip_count' => count($rows),
            'day_type' => $dayType?->value,
            'day_type_label' => $dayType?->label(),
            'reference_date' => $referenceDate,
            'modes' => $modes,
            'groups' => array_values($groups),
        ];
    }

    /**
     * Flache Ergebniszeilen: je Trip erster Halt (Abfahrt) + letzter Halt (Ankunft),
     * dazu das Wochenmuster der service_id aus `calendar`.
     *
     * @param  array<int, string>|null  $serviceIds  Auf diese service_ids einschränken; null = alle
     * @return array<int, object>
     */
    private function fetchRows(string $line, ?array $serviceIds): array
    {
        $bindings = [$line, $line];
        $serviceFilter = '';

        if ($serviceIds !== null) {
            $serviceFilter = ' and f.service_id in ('.implode(', ', array_fill(0, count($serviceIds), '?')).')';
            $bindings = array_merge($bindings, $serviceIds);
        }

        return DB::select(
            <<<SQL
            select f.trip_id, f.service_id, f.route_type,
                   f.stop_name      as start_stop,
                   f.departure_time as departure_time,
                   l.stop_name      as end_stop,
                   l.arrival_time   as arrival_time,
                   c.service_id     as calendar_service_id,
                   c.monday, c.tuesday, c.wednesday, c.thursday, c.friday, c.saturday, c.sunday
            from (
                select st.trip_id, t.service_id, s.stop_name, st.departure_time, r.route_type,
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
            left join calendar c on c.service_id = f.service_id
            where f.rn = 1{$serviceFilter}
            order by start_stop, end_stop, f.departure_time
            SQL,
            $bindings,
        );
    }

    /**
     * Wöchentlich wiederkehrende Verkehrstage in Kurzform ("Mo-Fr", "Sa, So", "täglich").
     * Zusammenhängende Wochentage ab drei werden zu einer Spanne verdichtet.
     *
     * Null, wenn die Fahrt kein Wochenmuster hat — entweder weil zur service_id gar keine
     * calendar-Zeile existiert (bei gtfs.de häufig) oder weil darin kein Wochentag gesetzt
     * ist. Solche Services verkehren ausschließlich an Einzelterminen aus calendar_dates;
     * die liefert serviceDates() als reine Kalenderdaten (Formatierung: Frontend).
     */
    private function dayPattern(object $row): ?string
    {
        // LEFT JOIN ohne Treffer: zur service_id existiert keine calendar-Zeile.
        if ($row->calendar_service_id === null) {
            return null;
        }

        $active = [];
        foreach (self::WEEKDAYS as $column => $kuerzel) {
            if ((bool) $row->{$column}) {
                $active[] = $kuerzel;
            }
        }

        if (count($active) === count(self::WEEKDAYS)) {
            return 'täglich';
        }

        if ($active === []) {
            return null;
        }

        $runs = [];
        $current = [];
        foreach (self::WEEKDAYS as $kuerzel) {
            if (in_array($kuerzel, $active, true)) {
                $current[] = $kuerzel;

                continue;
            }
            if ($current !== []) {
                $runs[] = $current;
                $current = [];
            }
        }
        if ($current !== []) {
            $runs[] = $current;
        }

        $parts = array_map(static function (array $run): string {
            $count = count($run);

            return $count >= 3 ? $run[0].'-'.$run[$count - 1] : implode(', ', $run);
        }, $runs);

        return implode(', ', $parts);
    }

    /**
     * Einzeltermine (calendar_dates, exception_type 1) je service_id — für Services
     * ohne Wochenmuster die einzige Auskunft darüber, wann sie verkehren.
     *
     * @param  array<int, string>  $serviceIds
     * @return array<string, array<int, string>>
     */
    private function serviceDates(array $serviceIds): array
    {
        if ($serviceIds === []) {
            return [];
        }

        return CalendarDate::query()
            ->whereIn('service_id', $serviceIds)
            ->where('exception_type', 1)
            ->orderBy('date')
            ->get(['service_id', 'date'])
            ->groupBy('service_id')
            ->map(static fn ($rows): array => $rows
                ->map(static fn (CalendarDate $row): string => $row->date instanceof \DateTimeInterface
                    ? $row->date->format('Y-m-d')
                    : (string) $row->date)
                ->all())
            ->all();
    }

    /**
     * @return array{line: string, trip_count: int, day_type: string|null, day_type_label: string|null, reference_date: string|null, modes: array<int, string>, groups: array<int, mixed>}
     */
    private function emptyResult(string $line, FahrplanTyp $dayType, ?string $referenceDate = null): array
    {
        return [
            'line' => $line,
            'trip_count' => 0,
            'day_type' => $dayType->value,
            'day_type_label' => $dayType->label(),
            'reference_date' => $referenceDate,
            'modes' => [],
            'groups' => [],
        ];
    }
}
