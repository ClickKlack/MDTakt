<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Eine angelegte Umlauf-Entscheidung samt Hinweisen.
 *
 * `warnings` ist bewusst Teil der Erfolgsantwort: Eine knappe Wendezeit oder ein Linienwechsel
 * sind keine Fehler — sie sollen aber auffallen (KURSE §4).
 *
 * @property-read array<string, mixed> $resource
 */
final class TripLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'kind' => $this->resource['kind'],
            'stop_id' => $this->resource['stop_id'],
            'from_trip' => $this->resource['from_trip'],
            'to_trip' => $this->resource['to_trip'],
            'turnaround_seconds' => $this->resource['turnaround_seconds'],
            'note' => $this->resource['note'],
            'warnings' => $this->resource['warnings'],
        ];
    }
}
