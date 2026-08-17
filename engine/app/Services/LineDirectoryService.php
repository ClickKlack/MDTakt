<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RouteType;
use App\Models\Route;
use App\Models\Trip;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Das Linienverzeichnis der MVB — eine Zeile je Linienbezeichnung, nicht je GTFS-Route.
 *
 * Beides fällt meist zusammen, aber nicht immer: dieselbe Bezeichnung kann auf mehrere
 * Routen zeigen, wenn ein Schienenersatzverkehr sie als Bus führt (N2 im Feed 08/2026 —
 * Tram-Route 18099 und Bus-Route 17551 auf derselben Strecke). Als Linien-Identität gilt
 * im System durchgängig `route_short_name`: Linienfarben liegen darauf, der Trips-Endpunkt
 * filtert danach. Das Verzeichnis folgt dem, statt eine Linie doppelt zu führen.
 */
final class LineDirectoryService
{
    /**
     * @return Collection<int, array{
     *     route_short_name: string,
     *     route_type: int,
     *     mode: string,
     *     modes: array<int, string>,
     *     route_ids: array<int, string>,
     *     color: string|null
     * }>
     */
    public function allLines(): Collection
    {
        // Fahrtenzahl je Route bestimmt, welche Route die Linie primär prägt.
        $tripCounts = Trip::query()
            ->select('route_id', DB::raw('count(*) as trip_count'))
            ->groupBy('route_id')
            ->pluck('trip_count', 'route_id');

        $lines = Route::query()
            ->with('lineColor')
            ->get()
            ->groupBy('route_short_name')
            ->map(function (Collection $routes, string $shortName) use ($tripCounts): array {
                // Primäre Route: die mit den meisten Fahrten, bei Gleichstand der kleinere
                // route_type (Tram vor Bus) — deterministisch, damit die Anzeige nicht springt.
                $primary = $routes
                    ->sort(function (Route $a, Route $b) use ($tripCounts): int {
                        $byTrips = (int) ($tripCounts[$b->route_id] ?? 0) <=> (int) ($tripCounts[$a->route_id] ?? 0);

                        return $byTrips !== 0 ? $byTrips : $a->route_type <=> $b->route_type;
                    })
                    ->first();

                return [
                    'route_short_name' => $shortName,
                    'route_type' => $primary->route_type,
                    'mode' => RouteType::modeFor($primary->route_type),
                    'modes' => $routes
                        ->map(static fn (Route $r): string => RouteType::modeFor($r->route_type))
                        ->unique()
                        ->sort()
                        ->values()
                        ->all(),
                    'route_ids' => $routes->pluck('route_id')->sort()->values()->all(),
                    'color' => $primary->lineColor?->color,
                ];
            })
            ->sortBy([
                ['route_type', 'asc'],
                ['route_short_name', 'asc'],
            ])
            ->values();

        $mehrfach = $lines->filter(static fn (array $line): bool => count($line['route_ids']) > 1);

        if ($mehrfach->isNotEmpty()) {
            Log::debug('Line names served by multiple GTFS routes', [
                'lines' => $mehrfach->pluck('route_short_name')->all(),
            ]);
        }

        return $lines;
    }
}
