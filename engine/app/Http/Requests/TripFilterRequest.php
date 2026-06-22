<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Validiert die optionalen Filter des GET /api/v1/trips-Endpunkts.
 * Der zeitliche Feinfilter (time=) folgt erst in I-05.
 */
final class TripFilterRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'line' => ['nullable', 'string', 'max:255'],
            'stop' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{date: string|null, line: string|null, stop: string|null}
     */
    public function filters(): array
    {
        return [
            'date' => $this->query('date'),
            'line' => $this->query('line'),
            'stop' => $this->query('stop'),
        ];
    }
}
