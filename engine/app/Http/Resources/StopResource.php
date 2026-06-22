<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Stop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * MVB-Haltestelle (GTFS stop).
 *
 * @mixin Stop
 */
final class StopResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'stop_id' => $this->stop_id,
            'stop_name' => $this->stop_name,
            'lat' => $this->lat,
            'lon' => $this->lon,
        ];
    }
}
