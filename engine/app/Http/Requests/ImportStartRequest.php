<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Validiert den Start eines GTFS-Imports: die Basistabellen (ohne stop_times)
 * plus optionalen Feed-Datenstand. stop_times folgen separat per Chunk.
 */
final class ImportStartRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'routes' => ['present', 'array'],
            'routes.*.route_id' => ['required', 'string'],
            'routes.*.route_short_name' => ['required', 'string'],
            'routes.*.route_type' => ['required', 'integer'],

            'stops' => ['present', 'array'],
            'stops.*.stop_id' => ['required', 'string'],
            'stops.*.stop_name' => ['required', 'string'],
            'stops.*.lat' => ['nullable', 'numeric'],
            'stops.*.lon' => ['nullable', 'numeric'],

            'trips' => ['present', 'array'],
            'trips.*.trip_id' => ['required', 'string'],
            'trips.*.route_id' => ['required', 'string'],
            'trips.*.service_id' => ['required', 'string'],
            'trips.*.block_id' => ['nullable', 'string'],
            'trips.*.direction_id' => ['nullable', 'integer', 'in:0,1'],

            // calendar.txt (reguläres Wochenmuster) — optional, da manche Feeds nur calendar_dates nutzen.
            'calendar' => ['sometimes', 'array'],
            'calendar.*.service_id' => ['required', 'string'],
            'calendar.*.monday' => ['required', 'boolean'],
            'calendar.*.tuesday' => ['required', 'boolean'],
            'calendar.*.wednesday' => ['required', 'boolean'],
            'calendar.*.thursday' => ['required', 'boolean'],
            'calendar.*.friday' => ['required', 'boolean'],
            'calendar.*.saturday' => ['required', 'boolean'],
            'calendar.*.sunday' => ['required', 'boolean'],
            'calendar.*.start_date' => ['required', 'date_format:Y-m-d'],
            'calendar.*.end_date' => ['required', 'date_format:Y-m-d'],

            'calendar_dates' => ['present', 'array'],
            'calendar_dates.*.service_id' => ['required', 'string'],
            'calendar_dates.*.date' => ['required', 'date_format:Y-m-d'],
            'calendar_dates.*.exception_type' => ['required', 'integer', 'in:1,2'],

            'feed_info' => ['sometimes', 'array'],
            'feed_info.feed_version' => ['nullable', 'string'],
            'feed_info.feed_start_date' => ['nullable', 'date_format:Y-m-d'],
            'feed_info.feed_end_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
