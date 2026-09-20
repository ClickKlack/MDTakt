<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StopGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Eine Haltestelle als Betriebspunkt.
 *
 * @mixin StopGroup
 */
final class StopGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_via' => $this->created_via->value,
            'note' => $this->note,
        ];
    }
}
