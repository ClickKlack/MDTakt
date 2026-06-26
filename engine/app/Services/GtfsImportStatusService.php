<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GtfsImportStatus;
use App\Http\Resources\GtfsImportRunResource;
use App\Models\GtfsImportRun;
use Illuminate\Support\Facades\DB;

/**
 * Liefert Import-Historie und aktuellen GTFS-Datenstand.
 *
 * Gemeinsame Abfrage-Logik für die Token-Variante (Collector, NAS) und die
 * Sanctum-Variante (Admin-Schaltzentrale) — damit kein Duplikat entsteht.
 */
final class GtfsImportStatusService
{
    /** Tabellen, deren Bestand als Datenstand ausgewiesen wird. */
    private const TABLES = ['routes', 'stops', 'trips', 'stop_times', 'calendar', 'calendar_dates'];

    private const MAX_PER_PAGE = 100;

    private const DEFAULT_PER_PAGE = 10;

    /**
     * Aktueller Datenstand + paginierte Import-Historie (neueste zuerst).
     *
     * @return array{
     *     current: array{
     *         last_success_at: string|null,
     *         feed_version: string|null,
     *         feed_start_date: string|null,
     *         feed_end_date: string|null,
     *         tables: array<string, int>
     *     },
     *     runs: array<int, array<string, mixed>>,
     *     pagination: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function status(?int $page = 1, ?int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $perPage = max(1, min($perPage ?? self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE));
        $page = max(1, $page ?? 1);

        $total = GtfsImportRun::query()->count();
        $lastPage = (int) max(1, (int) ceil($total / $perPage));

        $runs = GtfsImportRun::query()
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        $lastSuccess = GtfsImportRun::query()
            ->where('status', GtfsImportStatus::Success)
            ->orderByDesc('finished_at')
            ->first();

        $tables = [];
        foreach (self::TABLES as $table) {
            $tables[$table] = DB::table($table)->count();
        }

        return [
            'current' => [
                'last_success_at' => $lastSuccess?->finished_at?->toIso8601String(),
                'feed_version' => $lastSuccess?->feed_version,
                'feed_start_date' => $lastSuccess?->feed_start_date?->toDateString(),
                'feed_end_date' => $lastSuccess?->feed_end_date?->toDateString(),
                'tables' => $tables,
            ],
            'runs' => GtfsImportRunResource::collection($runs)->resolve(),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }
}
