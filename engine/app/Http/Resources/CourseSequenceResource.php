<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Die Antwort eines Mengen-Laufs in der Fahrplan-Matrix.
 *
 * Listet die Schlüssel explizit auf, statt das Service-Ergebnis durchzureichen: So steht der
 * Vertrag an einer Stelle und verschiebt sich nicht still, wenn der Service ein Feld ergänzt.
 *
 * @property-read array<string, mixed> $resource
 */
final class CourseSequenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'action' => $this->resource['action'],
            'line_version' => $this->resource['line_version'],
            'direction' => $this->resource['direction'],
            'pattern' => $this->resource['pattern'],
            'range' => $this->resource['range'],
            'summary' => $this->resource['summary'],
            'assignments' => $this->resource['assignments'],
            'removals' => $this->resource['removals'],
            'unchanged' => $this->resource['unchanged'],
            'skipped' => $this->resource['skipped'],
            'conflicts' => $this->resource['conflicts'],
            'warnings' => $this->resource['warnings'],
        ];
    }
}
