<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Antwort des Sichtungs-Eingangs. `watermark` ist informativ — der Tracker wählt über seine eigene
 * Sync-Spalte aus; `unmatched_fingerprints` macht Laufwege ohne Treffer auf beiden Seiten sichtbar.
 *
 * @property-read array<string, mixed> $resource
 */
final class SightingIngestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'received' => $this->resource['received'],
            'results' => $this->resource['results'],
            'unmatched_fingerprints' => $this->resource['unmatched_fingerprints'],
            'watermark' => $this->resource['watermark'],
        ];
    }
}
