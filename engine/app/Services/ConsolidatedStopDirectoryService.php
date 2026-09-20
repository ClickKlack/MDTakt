<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Haltestellenverzeichnis aus dem Konsolidat — eine Zeile je **physischem Punkt**.
 *
 * Gegenstück zu {@see LineDirectoryService} für Halte. Die Identität ist die
 * `consolidated_stops.id`, nicht die volatile GTFS-`stop_id`: Namensgleiche Halte im Umkreis
 * von 12 m sind zu einer Identität verschmolzen (FAHRPLANPERIODEN §5.1), Steige bewusst
 * eingeschlossen. Genau diese ID ist der Eingabewert der Umlauf-Pflege.
 *
 * Der Name kommt aus der Attribut-Historie, „latest wins" — Namen ändern sich, ohne dass der
 * Halt ein anderer wird.
 */
final class ConsolidatedStopDirectoryService
{
    public function __construct(private readonly ConsolidatedStopNameResolver $stopNames) {}

    /**
     * @return Collection<int, array{id: int, name: string, lat: float|null, lon: float|null}>
     */
    public function allStops(): Collection
    {
        $namen = $this->stopNames->namesForAll();

        return DB::table('consolidated_stops')
            ->select('id', 'anchor_lat', 'anchor_lon')
            ->get()
            ->map(static fn (object $h): array => [
                'id' => (int) $h->id,
                'name' => $namen[$h->id] ?? '—',
                'lat' => $h->anchor_lat === null ? null : (float) $h->anchor_lat,
                'lon' => $h->anchor_lon === null ? null : (float) $h->anchor_lon,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}
