<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GtfsImportRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Antwort des GTFS-Imports: Lauf-Status und Anzahl verarbeiteter Zeilen je Tabelle.
 *
 * @mixin GtfsImportRun
 */
final class GtfsImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, int> $counts */
        $counts = $this->counts ?? [];

        return [
            'run_id' => $this->id,
            'status' => $this->status->value,
            'imported' => [
                'routes' => $counts['routes'] ?? 0,
                'stops' => $counts['stops'] ?? 0,
                'trips' => $counts['trips'] ?? 0,
                'stop_times' => $counts['stop_times'] ?? 0,
                'calendar' => $counts['calendar'] ?? 0,
                'calendar_dates' => $counts['calendar_dates'] ?? 0,
            ],
        ];
    }
}
