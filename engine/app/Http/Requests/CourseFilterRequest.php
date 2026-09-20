<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FahrplanTyp;
use App\Models\SchedulePeriod;
use Illuminate\Validation\Rule;

/**
 * Filter der Kursübersicht. Periode und Fahrplantyp sind Pflicht — ein Umlauf gehört immer zu
 * genau einem Strang, und dieselbe Nummer bedeutet in einem anderen Strang etwas anderes.
 */
final class CourseFilterRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'period' => ['required', 'integer', 'exists:schedule_periods,id'],
            'day_type' => ['required', Rule::enum(FahrplanTyp::class)],
            'line' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function period(): SchedulePeriod
    {
        return SchedulePeriod::query()->findOrFail($this->integer('period'));
    }

    public function dayType(): FahrplanTyp
    {
        return FahrplanTyp::from((string) $this->query('day_type'));
    }

    public function line(): ?string
    {
        $wert = $this->query('line');

        return is_string($wert) && $wert !== '' ? $wert : null;
    }
}
