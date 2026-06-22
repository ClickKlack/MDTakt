<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\RouteType;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GTFS-Fahrt (trip). route_short_name/route_type/mode werden nur ausgegeben,
 * wenn die route-Relation geladen ist (Matching-Vorbereitung).
 *
 * @mixin Trip
 */
final class TripResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'trip_id' => $this->trip_id,
            'route_id' => $this->route_id,
            'route_short_name' => $this->whenLoaded('route', fn () => $this->route->route_short_name),
            'route_type' => $this->whenLoaded('route', fn () => $this->route->route_type),
            'mode' => $this->whenLoaded('route', fn () => RouteType::modeFor($this->route->route_type)),
            'service_id' => $this->service_id,
            'block_id' => $this->block_id,
            'direction_id' => $this->direction_id,
        ];
    }
}
