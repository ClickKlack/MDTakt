<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Die Antwort des Haltestellen-Editors.
 *
 * Listet die Schlüssel explizit auf, statt das Service-Ergebnis durchzureichen: So steht der
 * Vertrag an einer Stelle und verschiebt sich nicht still, wenn der Service ein Feld ergänzt.
 *
 * @property-read array<string, mixed> $resource
 */
final class StopLinkBoardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'stop_group' => $this->resource['stop_group'],
            'period' => $this->resource['period'],
            'day_type' => $this->resource['day_type'],
            'day_type_label' => $this->resource['day_type_label'],
            'stands' => $this->resource['stands'],
            'stand' => $this->resource['stand'],
            'ending' => $this->resource['ending'],
            'starting' => $this->resource['starting'],
            'open_count' => $this->resource['open_count'],
        ];
    }
}
