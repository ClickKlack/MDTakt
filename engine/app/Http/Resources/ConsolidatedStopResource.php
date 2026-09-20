<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ein Halt aus dem Konsolidat.
 *
 * Bewusst ohne `stop_id`: Das ist ein Begriff des Roh-Bestands, den gtfs.de pro Build neu
 * vergibt. Dauerhaft ist die Identität des physischen Punkts.
 *
 * @property-read array<string, mixed> $resource
 */
final class ConsolidatedStopResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'lat' => $this->resource['lat'],
            'lon' => $this->resource['lon'],
        ];
    }
}
