<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Zahlen für die Navigation: Warteschlange und „wartet auf Fahrplan".
 *
 * @property-read array{open: int, waiting: int} $resource
 */
final class SightingCountsResource extends JsonResource
{
    /**
     * @return array{open: int, waiting: int}
     */
    public function toArray(Request $request): array
    {
        return ['open' => $this->resource['open'], 'waiting' => $this->resource['waiting']];
    }
}
