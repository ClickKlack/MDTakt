<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SchedulePeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Netzweite Fahrplanperiode (FAHRPLANPERIODEN §4.1).
 *
 * @mixin SchedulePeriod
 */
final class SchedulePeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Ohne withCount() geladen (etwa direkt nach dem Anlegen) hat die Periode noch keine
        // Versionen — dann ist 0 die richtige Antwort, nicht null.
        $versionen = (int) ($this->line_versions_count ?? 0);

        return [
            'id' => $this->id,
            'label' => $this->label,
            'valid_from' => $this->valid_from->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'created_via' => $this->created_via->value,
            'created_via_label' => $this->created_via->label(),
            'line_version_count' => $versionen,
            'is_deletable' => $versionen === 0,
        ];
    }
}
