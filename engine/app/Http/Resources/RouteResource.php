<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\RouteType;
use App\Models\Route;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * MVB-Linie (GTFS route). `route_type` ist der rohe GTFS-Wert (0 = Tram, 3 = Bus),
 * `mode` der daraus abgeleitete maschinenlesbare Modus-Slug fürs Frontend.
 *
 * @mixin Route
 */
final class RouteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'route_id' => $this->route_id,
            'route_short_name' => $this->route_short_name,
            'route_type' => $this->route_type,
            'mode' => RouteType::modeFor($this->route_type),
            // Im Admin gepflegte Linienfarbe (null = noch nicht gesetzt → Frontend-Fallback).
            'color' => $this->lineColor?->color,
        ];
    }
}
