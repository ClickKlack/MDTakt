<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LineVersion;
use Illuminate\Validation\Validator;

/**
 * Übernahme gepflegter Kurse und Anschlüsse von einer Version auf ihre Nachfolgerin.
 *
 * Zwei Versionen sind nur dann sinnvoll füreinander Quelle und Ziel, wenn sie denselben Strang
 * bilden — dieselbe Linie, derselbe Fahrplantyp, dieselbe Periode. Sonst überträge man Pflege
 * auf einen Fahrplan, der nie in Konkurrenz zu ihr stand.
 */
final class CourseCarryoverRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'integer', 'exists:line_versions,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $quelle = $this->fromVersion();
            $ziel = $this->route('lineVersion');

            if (! $ziel instanceof LineVersion) {
                return;
            }

            if ($quelle->id === $ziel->id) {
                $validator->errors()->add('from', 'Quelle und Ziel sind dieselbe Version.');
            } elseif ($quelle->line !== $ziel->line) {
                $validator->errors()->add('from', 'Die Versionen gehören zu verschiedenen Linien.');
            } elseif ($quelle->day_type !== $ziel->day_type) {
                $validator->errors()->add('from', 'Die Versionen gehören zu verschiedenen Fahrplantypen.');
            } elseif ($quelle->period_id !== $ziel->period_id) {
                $validator->errors()->add('from', 'Die Versionen gehören zu verschiedenen Fahrplanperioden.');
            }
        });
    }

    public function fromVersion(): LineVersion
    {
        return LineVersion::query()->findOrFail($this->integer('from'));
    }
}
