<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TripLinkKind;
use App\Models\ConsolidatedTrip;
use App\Services\TripLinkRuleService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validiert eine Umlauf-Entscheidung (KURSE §4).
 *
 * **Hier wird aus einem Verstoß ein 422** — mit einer deutschen Meldung im einheitlichen
 * Envelope, statt als Exception. Die Regeln selbst liegen im {@see TripLinkRuleService}: Der
 * Mengen-Lauf über einen ganzen Zeitraum braucht dieselben Prüfungen, darf aber nicht abbrechen,
 * sondern überspringt eine Zeile. Gedoppelt liefen die beiden Fassungen auseinander, und dann
 * verknüpfte der Lauf etwas, das dieser Request abweist.
 *
 * Was hier bleibt, ist die Formregel: Welche Fahrt eine Entscheidung trägt, folgt aus ihrer Art.
 * Eine `start`-Zeile mit Vorgänger wäre ein Widerspruch in sich, und das hat mit der
 * Zulässigkeit eines Anschlusses nichts zu tun.
 */
final class TripLinkRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(TripLinkKind::class)],
            'from_trip_id' => ['nullable', 'integer', 'exists:consolidated_trips,id'],
            'to_trip_id' => ['nullable', 'integer', 'exists:consolidated_trips,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $kind = $this->kind();
            $von = $this->input('from_trip_id');
            $nach = $this->input('to_trip_id');

            // Welche Fahrt eine Entscheidung trägt, folgt aus ihrer Art. Eine `start`-Zeile
            // mit Vorgänger wäre ein Widerspruch in sich.
            if ($kind->hasFrom() && $von === null) {
                $validator->errors()->add('from_trip_id', 'Für diese Entscheidung fehlt die Fahrt, die hier endet.');

                return;
            }

            if ($kind->hasTo() && $nach === null) {
                $validator->errors()->add('to_trip_id', 'Für diese Entscheidung fehlt die Fahrt, die hier beginnt.');

                return;
            }

            if (! $kind->hasFrom() && $von !== null) {
                $validator->errors()->add('from_trip_id', 'Eine Fahrt, die den Umlauf beginnt, hat keine Vorgängerfahrt.');

                return;
            }

            if (! $kind->hasTo() && $nach !== null) {
                $validator->errors()->add('to_trip_id', 'Eine Fahrt, die den Umlauf beendet, hat keine Nachfolgefahrt.');

                return;
            }

            if ($kind !== TripLinkKind::Link) {
                return;
            }

            $this->pruefeAnschluss($validator, $this->fromTrip(), $this->toTrip());
        });
    }

    private function pruefeAnschluss(Validator $validator, ConsolidatedTrip $von, ConsolidatedTrip $nach): void
    {
        $grund = app(TripLinkRuleService::class)->rejectionFor($von, $nach);

        if ($grund !== null) {
            // Alle Ablehnungen hängen am selben Feld: Sie betreffen die Paarung, nicht eine
            // einzelne Angabe — und die zweite Fahrt ist die, die der Pflegende gerade wählt.
            $validator->errors()->add('to_trip_id', $grund->message);
        }
    }

    public function kind(): TripLinkKind
    {
        return TripLinkKind::from((string) $this->input('kind'));
    }

    public function fromTrip(): ?ConsolidatedTrip
    {
        $id = $this->input('from_trip_id');

        return $id === null ? null : ConsolidatedTrip::query()->with('lineVersion')->findOrFail($id);
    }

    public function toTrip(): ?ConsolidatedTrip
    {
        $id = $this->input('to_trip_id');

        return $id === null ? null : ConsolidatedTrip::query()->with('lineVersion')->findOrFail($id);
    }

    public function note(): ?string
    {
        $wert = $this->input('note');

        return is_string($wert) && $wert !== '' ? $wert : null;
    }
}
