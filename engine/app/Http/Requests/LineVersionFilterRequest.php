<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FahrplanTyp;
use App\Models\SchedulePeriod;
use Illuminate\Validation\Rule;

/**
 * Validiert die optionalen Filter von GET /api/v1/admin/line-versions.
 */
final class LineVersionFilterRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'line' => ['nullable', 'string', 'max:255'],
            'day_type' => ['nullable', Rule::enum(FahrplanTyp::class)],
            'period' => ['nullable', 'integer', 'exists:schedule_periods,id'],
        ];
    }

    public function line(): ?string
    {
        $value = $this->query('line');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function dayType(): ?FahrplanTyp
    {
        $value = $this->query('day_type');

        return is_string($value) && $value !== '' ? FahrplanTyp::from($value) : null;
    }

    /**
     * Ohne Angabe entscheidet der Dienst (laufende Periode). Mit Angabe ist auch eine
     * eingefrorene erlaubt — die Historie davor soll erreichbar bleiben.
     */
    public function period(): ?SchedulePeriod
    {
        $value = $this->query('period');

        return is_string($value) && $value !== ''
            ? SchedulePeriod::query()->find((int) $value)
            : null;
    }
}
