<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ergebnis einer Entscheidung. Beim Annehmen: wie viele Fahrten den Kurs bekamen (die ganze
 * Kette) und wie viele weitere offene Sichtungen dadurch von selbst bestätigt wurden.
 *
 * @property-read array<string, int> $resource
 */
final class SightingDecisionResource extends JsonResource
{
    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
