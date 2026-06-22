<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Calendar;
use App\Models\CalendarDate;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Filtert GTFS-Trips nach Betriebstag, Linie und Haltestelle.
 *
 * Vorbereitung für das Trip-Matching (SPEC §3.2, Stufen 1–3). Die zeitliche
 * Feinauswahl (±Zeitfenster, Stufe 4) folgt erst in I-05 (TripMatchingService).
 */
final class TripFilterService
{
    /**
     * Liefert alle Trips, die zu den gesetzten Filtern passen. Alle Kriterien
     * sind optional und werden mit UND verknüpft. Die route-Relation wird für
     * die Linien-Ausgabe direkt mitgeladen.
     *
     * @param  array{date?: string|null, line?: string|null, stop?: string|null}  $criteria
     * @return Collection<int, Trip>
     */
    public function filter(array $criteria): Collection
    {
        $date = $criteria['date'] ?? null;
        $line = $criteria['line'] ?? null;
        $stop = $criteria['stop'] ?? null;

        $query = Trip::query()->with('route');

        // Stufe 1 — Betriebstag: nur Trips mit an diesem Datum gültiger service_id
        if ($date !== null) {
            $query->whereIn('service_id', $this->activeServiceIds($date));
        }

        // Stufe 2 — Linie: route_short_name (alle MVB-Linien, Tram + Bus)
        if ($line !== null) {
            $query->whereHas('route', static fn (Builder $q): Builder => $q->where('route_short_name', $line));
        }

        // Stufe 3 — Haltestelle: Trip muss die gesichtete Haltestelle bedienen
        if ($stop !== null) {
            $query->whereHas('stopTimes', static fn (Builder $q): Builder => $q->where('stop_id', $stop));
        }

        $trips = $query->orderBy('trip_id')->get();

        Log::debug('Trip filter executed', [
            'date' => $date,
            'line' => $line,
            'stop' => $stop,
            'result_count' => $trips->count(),
        ]);

        return $trips;
    }

    /**
     * Ermittelt die an einem Betriebstag gültigen service_ids gemäß GTFS:
     * reguläres Wochenmuster aus `calendar` plus/minus Ausnahmen aus `calendar_dates`.
     *
     * @return array<int, string>
     */
    private function activeServiceIds(string $date): array
    {
        // Betriebstag ist ein reiner Kalendertag — Wochentag bestimmt das calendar-Flag.
        $weekday = strtolower(Carbon::parse($date)->format('l'));

        // Regulär aktiv: Wochenmuster trifft zu und Datum liegt im Gültigkeitszeitraum.
        $regular = Calendar::query()
            ->where($weekday, true)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('service_id');

        // exception_type 1 = Betrieb zusätzlich, 2 = Betrieb entfällt.
        $added = CalendarDate::query()
            ->whereDate('date', $date)
            ->where('exception_type', 1)
            ->pluck('service_id');

        $removed = CalendarDate::query()
            ->whereDate('date', $date)
            ->where('exception_type', 2)
            ->pluck('service_id');

        return $regular
            ->reject(static fn (string $id): bool => $removed->contains($id))
            ->merge($added)
            ->unique()
            ->values()
            ->all();
    }
}
