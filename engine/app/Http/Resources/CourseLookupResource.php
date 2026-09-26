<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Antwort der Kursauskunft für eine Abfahrt. Bei `found: false` nur der Grund
 * (`no-trip-match` | `ambiguous` | `no-course-assigned`); `ref` nur in der Sammelabfrage.
 *
 * @property-read array<string, mixed> $resource
 */
final class CourseLookupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $kopf = array_key_exists('ref', $this->resource) ? ['ref' => $this->resource['ref']] : [];

        if (! $this->resource['found']) {
            return $kopf + ['found' => false, 'reason' => $this->resource['reason']];
        }

        return $kopf + [
            'found' => true,
            'course_number' => $this->resource['course_number'],
            'display' => $this->resource['display'],
            'line' => $this->resource['line'],
            'matched_trip' => $this->resource['matched_trip'],
            'stop_resolved_via' => $this->resource['stop_resolved_via'],
        ];
    }
}
