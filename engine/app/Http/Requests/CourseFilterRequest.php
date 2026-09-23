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
            // Ohne Angabe waehlt die Engine den Stand, der heute enthaelt — sonst den letzten.
            'stand' => ['nullable', 'integer', 'min:0'],
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

    public function standIndex(): ?int
    {
        $wert = $this->query('stand');

        return $wert === null || $wert === '' ? null : (int) $wert;
    }

    public function line(): ?string
    {
        $wert = $this->query('line');

        return is_string($wert) && $wert !== '' ? $wert : null;
    }
}
