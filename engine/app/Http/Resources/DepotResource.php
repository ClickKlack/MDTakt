<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\DepotService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ein Betriebshof in Antwortform.
 *
 * Nimmt das fertige Array aus {@see DepotService::describe()} entgegen, nicht das
 * Model: `usage_count` und die Kurzform entstehen dort, und sie sollen nicht an zwei Stellen
 * gerechnet werden.
 *
 * @property-read array<string, mixed> $resource
 */
final class DepotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'short_name' => $this->resource['short_name'],
            'display' => $this->resource['display'],
            'stop_groups' => $this->resource['stop_groups'],
            'modes' => $this->resource['modes'],
            'active' => $this->resource['active'],
            'note' => $this->resource['note'],
            'usage_count' => $this->resource['usage_count'],
        ];
    }
}
