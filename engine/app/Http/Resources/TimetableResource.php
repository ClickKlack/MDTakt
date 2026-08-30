<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fahrplan-Matrix einer Linien-Version.
 *
 * Der Dienst liefert die Struktur bereits in Ausgabeform — die Resource hält den Vertrag
 * an einer Stelle fest, statt die Form im Service zu verstecken.
 *
 * @property-read array<string, mixed> $resource
 */
final class TimetableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'line_version' => $this->resource['line_version'],
            'period' => $this->resource['period'],
            'directions' => $this->resource['directions'],
        ];
    }
}
