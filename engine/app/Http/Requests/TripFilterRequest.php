<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ScheduleSource;
use Illuminate\Validation\Rule;

/**
 * Validiert die optionalen Filter des GET /api/v1/trips-Endpunkts.
 * Der zeitliche Feinfilter (time=) folgt erst in I-05.
 */
final class TripFilterRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'line' => ['nullable', 'string', 'max:255'],
            'stop' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', Rule::enum(ScheduleSource::class)],
        ];
    }

    /** Bestand, aus dem geantwortet wird — Vorgabe ist das Konsolidat. */
    public function source(): ScheduleSource
    {
        $value = $this->query('source');

        return is_string($value) && $value !== ''
            ? ScheduleSource::from($value)
            : ScheduleSource::Consolidated;
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
