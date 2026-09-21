<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CourseSequenceAction;
use App\Models\ConsolidatedTrip;
use App\Support\CourseNumberSequence;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Der markierte Spaltenbereich und die Nummernfolge.
 *
 * Bedient Vorschau (GET) und Anwenden (POST) mit denselben Regeln — deshalb durchgängig
 * `input()` statt `query()`.
 *
 * Das Muster wird hier **probeweise entfaltet**, damit eine unlesbare Eingabe im 422-Envelope
 * landet und nicht als 500er aus dem Dienst fällt. Die Meldung des Parsers ist schon für den
 * Menschen geschrieben und wandert unverändert weiter.
 */
final class CourseSequenceRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from_trip_id' => ['required', 'integer', 'exists:consolidated_trips,id'],
            'to_trip_id' => ['required', 'integer', 'exists:consolidated_trips,id'],
            'action' => ['nullable', Rule::enum(CourseSequenceAction::class)],
            'pattern' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->pruefeZugehoerigkeit($validator);

            if ($this->action() !== CourseSequenceAction::Assign) {
                return;
            }

            try {
                CourseNumberSequence::parse((string) $this->input('pattern'));
            } catch (InvalidArgumentException $e) {
                $validator->errors()->add('pattern', $e->getMessage());
            }
        });
    }

    /**
     * Beide Fahrten müssen zu der Version gehören, deren Matrix bearbeitet wird.
     *
     * Ohne diese Prüfung fiele der Fehlgriff erst im Dienst auf — und zwar als „liegen in
     * verschiedenen Richtungen", was in die Irre führte.
     */
    private function pruefeZugehoerigkeit(Validator $validator): void
    {
        $versionId = (int) $this->route('lineVersion')?->id;

        $fremde = ConsolidatedTrip::query()
            ->whereIn('id', [$this->integer('from_trip_id'), $this->integer('to_trip_id')])
            ->where('line_version_id', '!=', $versionId)
            ->exists();

        if ($fremde) {
            $validator->errors()->add(
                'from_trip_id',
                'Mindestens eine der markierten Fahrten gehört zu einer anderen Fahrplan-Version.',
            );
        }
    }

    public function action(): CourseSequenceAction
    {
        $wert = $this->input('action');

        return $wert === null || $wert === ''
            ? CourseSequenceAction::Assign
            : CourseSequenceAction::from((string) $wert);
    }

    /** Bei `clear` ohne Bedeutung — der Dienst entfaltet dann gar nichts. */
    public function pattern(): ?string
    {
        $wert = $this->input('pattern');

        return is_string($wert) && $wert !== '' ? $wert : null;
    }
}
