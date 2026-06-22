<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GtfsImportRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Einzelner GTFS-Import-Lauf in der Historie.
 *
 * @mixin GtfsImportRun
 */
final class GtfsImportRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'feed_version' => $this->feed_version,
            'feed_start_date' => $this->feed_start_date?->toDateString(),
            'feed_end_date' => $this->feed_end_date?->toDateString(),
            'counts' => $this->counts,
            'error_message' => $this->error_message,
        ];
    }
}
