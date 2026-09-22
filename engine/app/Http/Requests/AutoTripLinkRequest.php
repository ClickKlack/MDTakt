<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AutoLinkAction;
use App\Enums\FahrplanTyp;
use App\Models\SchedulePeriod;
use App\Models\StopGroup;
use App\Support\AutoLinkScope;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Der Umfang eines Mengen-Laufs im Haltestellen-Editor.
 *
 * Bedient Vorschau (GET) und Anwenden (POST) mit denselben Regeln — deshalb durchgängig
 * `input()` statt `query()`: Die Bedingungen sind dieselben, und zwei Fassungen liefen
 * auseinander.
 */
final class AutoTripLinkRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        // Der Schalter kommt bei der Vorschau (GET) als Zeichenkette an — siehe
        // {@see ApiFormRequest::normalizeBooleans()}.
        $this->normalizeBooleans('include_terminals', 'through_stop');
    }

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
            'from_trip_id' => ['required', 'integer', 'exists:consolidated_trips,id'],
            'to_trip_id' => ['required', 'integer', 'exists:consolidated_trips,id'],
            'action' => ['nullable', Rule::enum(AutoLinkAction::class)],
            'lines' => ['nullable', 'array'],
            'lines.*' => ['string', 'max:8'],
            'mode' => ['nullable', 'string', 'in:tram,bus'],
            // 0 ist erlaubt: An einer Endstelle mit getrennten Bahnsteigen kann die Abfahrt
            // zeitgleich mit der Ankunft liegen, und das ist kein Fehler.
            'min_turnaround_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'max_turnaround_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'include_terminals' => ['nullable', 'boolean'],
            'through_stop' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->maxTurnaroundSeconds() < $this->minTurnaroundSeconds()) {
                $validator->errors()->add(
                    'max_turnaround_minutes',
                    'Die Höchstwende darf nicht unter der Mindestwende liegen — es bliebe kein Zeitfenster übrig.',
                );
            }
        });
    }

    public function scope(): AutoLinkScope
    {
        return new AutoLinkScope(
            stopGroup: StopGroup::query()->findOrFail($this->integer('stop_group')),
            period: SchedulePeriod::query()->findOrFail($this->integer('period')),
            dayType: FahrplanTyp::from((string) $this->input('day_type')),
            standIndex: $this->standIndex(),
            lines: $this->lines(),
            mode: $this->mode(),
            fromTripId: $this->integer('from_trip_id'),
            toTripId: $this->integer('to_trip_id'),
            minTurnaroundSeconds: $this->minTurnaroundSeconds(),
            maxTurnaroundSeconds: $this->maxTurnaroundSeconds(),
            action: $this->action(),
            includeTerminals: $this->boolean('include_terminals'),
            throughStop: $this->boolean('through_stop'),
        );
    }

    public function action(): AutoLinkAction
    {
        $wert = $this->input('action');

        return $wert === null || $wert === '' ? AutoLinkAction::Link : AutoLinkAction::from((string) $wert);
    }

    public function standIndex(): ?int
    {
        $wert = $this->input('stand');

        return $wert === null || $wert === '' ? null : (int) $wert;
    }

    /**
     * @return array<int, string>
     */
    public function lines(): array
    {
        $wert = $this->input('lines');

        if (! is_array($wert)) {
            return [];
        }

        return array_values(array_unique(array_map(static fn ($linie): string => (string) $linie, $wert)));
    }

    public function mode(): ?string
    {
        $wert = $this->input('mode');

        return is_string($wert) && $wert !== '' ? $wert : null;
    }

    private function minTurnaroundSeconds(): int
    {
        $wert = $this->input('min_turnaround_minutes');

        return ($wert === null || $wert === ''
            ? (int) config('mdtakt.courses.min_turnaround_minutes')
            : (int) $wert) * 60;
    }

    private function maxTurnaroundSeconds(): int
    {
        $wert = $this->input('max_turnaround_minutes');

        return ($wert === null || $wert === ''
            ? (int) config('mdtakt.courses.max_turnaround_minutes')
            : (int) $wert) * 60;
    }
}
