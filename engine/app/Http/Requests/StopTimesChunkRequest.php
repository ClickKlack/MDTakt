<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Validiert einen Chunk an stop_times, der an einen laufenden Import angehängt wird.
 */
final class StopTimesChunkRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stop_times' => ['present', 'array'],
            'stop_times.*.trip_id' => ['required', 'string'],
            'stop_times.*.stop_id' => ['required', 'string'],
            'stop_times.*.arrival_time' => ['nullable', 'string', 'max:8'],
            'stop_times.*.departure_time' => ['nullable', 'string', 'max:8'],
            'stop_times.*.stop_sequence' => ['required', 'integer', 'min:0'],
        ];
    }
}
