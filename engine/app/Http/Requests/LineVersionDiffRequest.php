<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LineVersion;
use Illuminate\Validation\Validator;

/**
 * Validiert den Versionsvergleich.
 *
 * Zwei Versionen sind nur dann sinnvoll vergleichbar, wenn sie dieselbe Linie, denselben
 * Fahrplantyp und dieselbe Periode tragen — sonst verglichen wir Fahrpläne, die nie in
 * Konkurrenz zueinander standen, und jede Fahrt erschiene als neu oder entfallen.
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
            } elseif ($von->period_id !== $nach->period_id) {
                $validator->errors()->add('to', 'Die Versionen gehören zu verschiedenen Fahrplanperioden.');
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
