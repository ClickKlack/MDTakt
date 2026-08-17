<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SchoolHoliday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schulferien-Zeitraum.
 *
 * @mixin SchoolHoliday
 */
final class SchoolHolidayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
        ];
    }
}
