<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FahrplanTyp;
use App\Models\SchedulePeriod;
use App\Models\StopGroup;
use Illuminate\Validation\Rule;

/**
 * Filter des Haltestellen-Editors: Haltestelle, Periode, Fahrplantyp — und optional der
 * Versionsstand.
 *
 * `stop_group` ist die **Haltestelle** als Betriebspunkt, nicht ein einzelner Halt: An einer
 * Endstelle liegen Ankunft und Abfahrt oft auf verschiedenen Punkten (KURSE §3.1).
 *
 * Periode und Fahrplantyp sind Pflicht — dieselbe Haltestelle trägt je Periode und
 * Betriebstag-Typ einen anderen Fahrplan.
 */
final class StopLinkBoardRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'stop_group' => ['required', 'integer', 'exists:stop_groups,id'],
            'period' => ['required', 'integer', 'exists:schedule_periods,id'],
            'day_type' => ['required', Rule::enum(FahrplanTyp::class)],
            'stand' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function stopGroup(): StopGroup
    {
        return StopGroup::query()->findOrFail($this->integer('stop_group'));
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
}
