<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FahrplanTyp;
use App\Enums\ScheduleSource;
use Illuminate\Validation\Rule;

/**
 * Validiert den optionalen Fahrplantyp-Filter von GET /api/v1/lines/{line}/trips.
 */
final class LineTripsRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'day_type' => ['nullable', Rule::enum(FahrplanTyp::class)],
            'source' => ['nullable', Rule::enum(ScheduleSource::class)],
        ];
    }

    /**
     * Angeforderter Fahrplantyp; null = ungefiltert (alle Betriebstage).
     */
    public function dayType(): ?FahrplanTyp
    {
        $value = $this->query('day_type');

        return is_string($value) && $value !== '' ? FahrplanTyp::from($value) : null;
    }

    /** Bestand, aus dem geantwortet wird — Vorgabe ist das Konsolidat. */
    public function source(): ScheduleSource
    {
        $value = $this->query('source');

        return is_string($value) && $value !== ''
            ? ScheduleSource::from($value)
            : ScheduleSource::Consolidated;
    }
}
