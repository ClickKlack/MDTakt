<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Validiert das Anlegen/Ändern einer Schulferien-Periode.
 */
final class SchoolHolidayRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }
}
