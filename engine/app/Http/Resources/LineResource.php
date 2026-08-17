<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Eine MVB-Linie — identifiziert über `route_short_name`, nicht über die GTFS-route_id.
 *
 * `route_type`/`mode` beschreiben die prägende Route (die mit den meisten Fahrten),
 * `modes` und `route_ids` alle beteiligten. Mehr als ein Eintrag dort heißt: die
 * Bezeichnung wird von mehreren Routen geführt (z. B. Schienenersatzverkehr als Bus).
 *
 * @property-read array{route_short_name: string, route_type: int, mode: string, modes: array<int, string>, route_ids: array<int, string>, color: string|null} $resource
 */
final class LineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'route_short_name' => $this->resource['route_short_name'],
            'route_type' => $this->resource['route_type'],
            'mode' => $this->resource['mode'],
            'modes' => $this->resource['modes'],
            'route_ids' => $this->resource['route_ids'],
            // Im Admin gepflegte Linienfarbe (null = noch nicht gesetzt → Frontend-Fallback).
            'color' => $this->resource['color'],
        ];
    }
}
