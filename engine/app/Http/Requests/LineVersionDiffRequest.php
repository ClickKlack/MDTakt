<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LineVersion;
use Illuminate\Validation\Validator;

/**
 * Validiert den Versionsvergleich.
 *
 * Zwei Versionen sind nur dann sinnvoll vergleichbar, wenn sie dieselbe Linie und denselben
 * Fahrplantyp tragen — sonst verglichen wir Fahrpläne, die nie in Konkurrenz zueinander
 * standen, und jede Fahrt erschiene als neu oder entfallen.
 *
 * **Die Periode gehört bewusst nicht mehr dazu** (21.09.2026). Das Argument trug für zwei
 * beliebige Versionen, aber nicht an der Periodengrenze: Die letzte Version der alten und
 * Version 1 der neuen Periode folgen unmittelbar aufeinander — das sind genau die beiden
 * Fahrpläne, die gegeneinander standen. Und ein Periodenwechsel ist der Moment, in dem die
 * Frage „was hat sich geändert?" am dringendsten ist. Die Sperre nahm sie unbeantwortbar.
 *
 * Der Vergleich zweier weit auseinanderliegender Perioden bleibt möglich und wenig
 * aussagekräftig — das ist hinnehmbar. Die Anzeige nennt zu jeder Version ihre Periode,
 * weil `version_no` je Periode wieder bei 1 beginnt und „v3 gegen v1" sonst rückwärts läse.
 */
final class LineVersionDiffRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'integer', 'exists:line_versions,id'],
            'to' => ['required', 'integer', 'different:from', 'exists:line_versions,id'],
            'include' => ['nullable', 'in:stops'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $von = LineVersion::query()->find($this->integer('from'));
            $nach = LineVersion::query()->find($this->integer('to'));

            if ($von->line !== $nach->line) {
                $validator->errors()->add('to', 'Die Versionen gehören zu verschiedenen Linien.');
            } elseif ($von->day_type !== $nach->day_type) {
                $validator->errors()->add('to', 'Die Versionen gehören zu verschiedenen Fahrplantypen.');
            }
        });
    }

    public function fromVersion(): LineVersion
    {
        return LineVersion::query()->findOrFail($this->integer('from'));
    }

    public function toVersion(): LineVersion
    {
        return LineVersion::query()->findOrFail($this->integer('to'));
    }

    public function withStops(): bool
    {
        return $this->query('include') === 'stops';
    }
}
