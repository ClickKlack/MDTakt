<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PeriodChangeOffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Systemvorschlag für einen Periodenwechsel (FAHRPLANPERIODEN §4.3).
 *
 * @mixin PeriodChangeOffer
 */
final class PeriodChangeOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'suggested_from' => $this->suggested_from->toDateString(),
            'changed_line_count' => $this->changed_line_count,
            'active_line_count' => $this->active_line_count,
            'share' => $this->active_line_count > 0
                ? round($this->changed_line_count / $this->active_line_count, 3)
                : null,
            'lines' => $this->lines,
            // Wie gut ist der Wechsel belegt? Ein Wechseltag am Rand des Feed-Fensters ruht auf
            // einer einzigen Beobachtung (§5.4 b) — das muss die Anzeige sagen können.
            'observed_until' => $this->observed_until,
            'single_day_observation' => (bool) $this->single_day_observation,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
