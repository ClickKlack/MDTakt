<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ein Umlauf samt der Linien, die er berührt.
 *
 * @property-read array<string, mixed> $resource
 */
final class CourseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'period_id' => $this->resource['period_id'],
            'day_type' => $this->resource['day_type'],
            'day_type_label' => $this->resource['day_type_label'],
            'number' => $this->resource['number'],
            'note' => $this->resource['note'],
            'trip_count' => $this->resource['trip_count'],
            'lines' => $this->resource['lines'],
            'first_departure' => $this->resource['first_departure'],
            'last_arrival' => $this->resource['last_arrival'],
            'duplicate' => $this->resource['duplicate'],
        ];
    }
}
