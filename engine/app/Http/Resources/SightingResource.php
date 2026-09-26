<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\SightingReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Eine Zeile der Prüfliste, wie {@see SightingReviewService::describe()} sie baut.
 *
 * @property-read array<string, mixed> $resource
 */
final class SightingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'mdkt_recording_id' => $this->resource['mdkt_recording_id'],
            'line' => $this->resource['line'],
            'course_number' => $this->resource['course_number'],
            'display' => $this->resource['display'],
            'hafas_stop_id' => $this->resource['hafas_stop_id'],
            'stop_name' => $this->resource['stop_name'],
            'service_date' => $this->resource['service_date'],
            'observed_at' => $this->resource['observed_at'],
            'departure_planned' => $this->resource['departure_planned'],
            'departure_actual' => $this->resource['departure_actual'],
            'route_fingerprint' => $this->resource['route_fingerprint'],
            'match' => $this->resource['match'],
            'match_attempts' => $this->resource['match_attempts'],
            'status' => $this->resource['status'],
            'status_label' => $this->resource['status_label'],
            'decided_at' => $this->resource['decided_at'],
            'decision_note' => $this->resource['decision_note'],
            'trip' => $this->resource['trip'],
            'local_course' => $this->resource['local_course'],
            'comparison' => $this->resource['comparison'],
            'chain_trip_count' => $this->resource['chain_trip_count'],
        ];
    }
}
