<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FahrplanTyp;
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
}
