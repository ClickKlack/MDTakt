<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

/**
 * Zuordnung eines Kurses zu einer Fahrt — und damit zu ihrer ganzen Kette.
 *
 * Entweder `course_id` (bestehender Umlauf) oder `number` (wird bei Bedarf angelegt). Beides
 * zugleich wäre mehrdeutig: Trügen die beiden verschiedene Nummern, wäre nicht klar, welche gilt.
 */
final class TripCourseRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'number' => ['nullable', 'string', 'max:8'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $hatId = $this->input('course_id') !== null;
            $hatNummer = $this->number() !== null;

            if (! $hatId && ! $hatNummer) {
                $validator->errors()->add('number', 'Gib eine Kursnummer an oder wähle einen bestehenden Umlauf.');
            }

            if ($hatId && $hatNummer) {
                $validator->errors()->add('number', 'Entweder Kursnummer oder bestehender Umlauf — nicht beides.');
            }
        });
    }

    public function courseId(): ?int
    {
        $wert = $this->input('course_id');

        return $wert === null ? null : (int) $wert;
    }

    public function number(): ?string
    {
        $wert = $this->input('number');

        return is_string($wert) && trim($wert) !== '' ? trim($wert) : null;
    }
}
