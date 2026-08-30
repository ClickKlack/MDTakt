<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unterschied zweier Linien-Versionen.
 *
 * @property-read array<string, mixed> $resource
 */
final class LineVersionDiffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from' => $this->resource['from'],
            'to' => $this->resource['to'],
            'summary' => $this->resource['summary'],
            'changed' => $this->resource['changed'],
            'added' => $this->resource['added'],
            'removed' => $this->resource['removed'],
        ];
    }
}
