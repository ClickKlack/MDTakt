<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Die Umläufe einer Linie als Tabelle: Halte als Zeilen, ein Kurs je Spalte — eine Tabelle je
 * Laufweg (`sections`).
 *
 * Listet die Schlüssel explizit auf, statt das Service-Ergebnis durchzureichen: So steht der
 * Vertrag an einer Stelle und verschiebt sich nicht still, wenn der Service ein Feld ergänzt.
 *
 * @property-read array<string, mixed> $resource
 */
final class CourseGridResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'line' => $this->resource['line'],
            'period' => $this->resource['period'],
            'day_type' => $this->resource['day_type'],
            'day_type_label' => $this->resource['day_type_label'],
            'stands' => $this->resource['stands'],
            'stand' => $this->resource['stand'],
            'sections' => $this->resource['sections'],
            'unassigned' => $this->resource['unassigned'],
            'summary' => $this->resource['summary'],
        ];
    }
}
