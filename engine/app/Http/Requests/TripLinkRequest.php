<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RouteType;
use App\Enums\TripLinkKind;
use App\Models\ConsolidatedTrip;
use App\Models\Depot;
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
            'depot_id' => ['nullable', 'integer', 'exists:depots,id'],
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
                $this->pruefeBetriebshof($validator, $kind);

                return;
            }

            // Ein Anschluss führt zu keinem Hof: Das Fahrzeug fährt weiter, es rückt nicht ein.
            if ($this->input('depot_id') !== null) {
                $validator->errors()->add(
                    'depot_id',
                    'Ein Anschluss führt zu keinem Betriebshof — die Angabe gehört an eine Fahrt, '
                    .'die aus dem Hof kommt oder in ihn fährt.',
                );

                return;
            }

            $this->pruefeAnschluss($validator, $this->fromTrip(), $this->toTrip());
        });
    }

    /**
     * Der Hof ist freiwillig — steht aber einer da, muss er das Fahrzeug aufnehmen können.
     *
     * Ein stillgelegter Hof wird neu nicht mehr vergeben: Er bleibt an alten Entscheidungen
     * lesbar, nimmt aber nichts Neues auf. Und ein Tram-Hof nimmt keinen Bus — wer das zulässt,
     * schreibt einen Umlauf fest, den es nicht geben kann.
     */
    private function pruefeBetriebshof(Validator $validator, TripLinkKind $kind): void
    {
        $id = $this->input('depot_id');

        if ($id === null) {
            return;
        }

        $hof = Depot::query()->find($id);

        if ($hof === null) {
            return;
        }

        if (! $hof->active) {
            $validator->errors()->add(
                'depot_id',
                sprintf('Der Betriebshof „%s" ist stillgelegt und nimmt keine neuen Fahrten mehr auf.', $hof->name),
            );

            return;
        }

        $fahrt = $kind === TripLinkKind::Start ? $this->toTrip() : $this->fromTrip();
        $mode = $fahrt === null ? null : RouteType::modeFor((int) $fahrt->route_type);

        if ($mode !== null && ! $hof->acceptsMode($mode)) {
            $validator->errors()->add(
                'depot_id',
                sprintf('Der Betriebshof „%s" nimmt dieses Verkehrsmittel nicht auf.', $hof->name),
            );
        }
    }

    public function depotId(): ?int
    {
        $wert = $this->input('depot_id');

        return $wert === null || $wert === '' ? null : (int) $wert;
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
