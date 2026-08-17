<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Calendar;
use App\Models\CalendarDate;
use Carbon\CarbonImmutable;

/**
 * Betriebstag-Logik auf dem rohen GTFS-Kalender: welche service_ids verkehren an
 * einem Datum, und welchen Zeitraum deckt der importierte Feed überhaupt ab.
 *
 * Herausgelöst aus dem TripFilterService, damit Trip-Filter und Fahrplantyp-Auflösung
 * dieselbe Regel benutzen statt zwei Kopien davon.
 */
final class ServiceDayResolver
{
    /**
     * Ermittelt die an einem Betriebstag gültigen service_ids gemäß GTFS:
     * reguläres Wochenmuster aus `calendar` plus/minus Ausnahmen aus `calendar_dates`.
     *
     * @return array<int, string>
     */
    public function activeServiceIds(string $date): array
    {
        return $this->activeServiceIdsForRange($date, $date)[$date] ?? [];
    }

    /**
     * Dasselbe für einen ganzen Zeitraum, aber mit zwei Abfragen statt drei je Tag —
     * die Auswertung läuft anschließend im Speicher. Nötig, weil die Fahrplantyp-Auflösung
     * das gesamte Feed-Fenster durchgeht.
     *
     * @return array<string, array<int, string>> Datum (Y-m-d) => service_ids
     */
    public function activeServiceIdsForRange(string $from, string $to): array
    {
        // Alle Wochenmuster, die den Zeitraum überhaupt berühren.
        $calendars = Calendar::query()
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->get();

        // exception_type 1 = Betrieb zusätzlich, 2 = Betrieb entfällt.
        $exceptions = CalendarDate::query()
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get()
            ->groupBy(static fn (CalendarDate $row): string => $row->date->format('Y-m-d'));

        $result = [];
        $day = CarbonImmutable::parse($from);
        $last = CarbonImmutable::parse($to);

        while ($day->lessThanOrEqualTo($last)) {
            $date = $day->toDateString();
            // Betriebstag ist ein reiner Kalendertag — Wochentag bestimmt das calendar-Flag.
            $weekday = strtolower($day->format('l'));

            $regular = $calendars
                ->filter(static fn (Calendar $c): bool => (bool) $c->{$weekday}
                    && $c->start_date->format('Y-m-d') <= $date
                    && $c->end_date->format('Y-m-d') >= $date)
                ->pluck('service_id');

            $dayExceptions = $exceptions->get($date, collect());
            $added = $dayExceptions->where('exception_type', 1)->pluck('service_id');
            $removed = $dayExceptions->where('exception_type', 2)->pluck('service_id');

            $result[$date] = $regular
                ->reject(static fn (string $id): bool => $removed->contains($id))
                ->merge($added)
                ->unique()
                ->values()
                ->all();

            $day = $day->addDay();
        }

        return $result;
    }

    /**
     * Gültigkeitsfenster des importierten Feeds — frühester bis spätester Betriebstag
     * über `calendar` und `calendar_dates`. Null, wenn kein Kalender importiert ist.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable}|null
     */
    public function feedWindow(): ?array
    {
        $from = [Calendar::query()->min('start_date'), CalendarDate::query()->min('date')];
        $to = [Calendar::query()->max('end_date'), CalendarDate::query()->max('date')];

        $from = array_filter($from);
        $to = array_filter($to);

        if ($from === [] || $to === []) {
            return null;
        }

        return [
            'from' => CarbonImmutable::parse(min($from)),
            'to' => CarbonImmutable::parse(max($to)),
        ];
    }
}
