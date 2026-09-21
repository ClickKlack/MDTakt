<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Die Antwort eines Mengen-Laufs im Haltestellen-Editor.
 *
 * Listet die Schlüssel explizit auf, statt das Service-Ergebnis durchzureichen: So steht der
 * Vertrag an einer Stelle und verschiebt sich nicht still, wenn der Service ein Feld ergänzt.
 *
 * @property-read array<string, mixed> $resource
 */
final class AutoTripLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'action' => $this->resource['action'],
            'stop_group' => $this->resource['stop_group'],
            'period' => $this->resource['period'],
            'day_type' => $this->resource['day_type'],
            'day_type_label' => $this->resource['day_type_label'],
            'stand' => $this->resource['stand'],
            'filter' => $this->resource['filter'],
            'defaults' => $this->resource['defaults'],
            'range' => $this->resource['range'],
            'summary' => $this->resource['summary'],
            'pairs' => $this->resource['pairs'],
            'removals' => $this->resource['removals'],
            'skipped' => $this->resource['skipped'],
        ];
    }
}
