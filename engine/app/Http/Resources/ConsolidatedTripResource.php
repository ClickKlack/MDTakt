<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Eine Fahrt aus dem Konsolidat.
 *
 * Bewusst ohne `trip_id`, `service_id`, `block_id`: Das sind Begriffe des Roh-Bestands, die
 * gtfs.de pro Build neu vergibt. Dauerhaft ist die `signature` — und die Version, in der die
 * Fahrt steht.
 *
 * @property-read array<string, mixed> $resource
 */
final class ConsolidatedTripResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'signature' => $this->resource['signature'],
            'route_short_name' => $this->resource['route_short_name'],
            'route_type' => $this->resource['route_type'],
            'mode' => $this->resource['mode'],
            'day_type' => $this->resource['day_type'],
            'version_no' => $this->resource['version_no'],
            'start_stop' => $this->resource['start_stop'],
            'end_stop' => $this->resource['end_stop'],
            'departure_time' => $this->resource['departure_time'],
            'arrival_time' => $this->resource['arrival_time'],
        ];
    }
}
