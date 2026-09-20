<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TripLinkKind;
use App\Models\ConsolidatedTrip;
use App\Models\LineVersionInterval;
use App\Services\StopGroupService;
use App\Services\TripLinkService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validiert eine Umlauf-Entscheidung (KURSE §4).
 *
 * Die fachlichen Prüfungen liegen bewusst hier und nicht im Service: So landet jeder Verstoß
 * im einheitlichen 422-Envelope mit einer deutschen Meldung, statt als Exception.
 *
 * Nicht geprüft wird der **Linienwechsel** — eine 1 wird in Sudenburg zur 13, das ist der
 * Normalfall einer Fahrzeugkette (K1). Er erscheint als Hinweis im Ergebnis, nicht als Fehler.
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
        if ($von->id === $nach->id) {
            $validator->errors()->add('to_trip_id', 'Eine Fahrt kann nicht an sich selbst anschließen.');

            return;
        }

        $vonVersion = $von->lineVersion;
        $nachVersion = $nach->lineVersion;

        if ($vonVersion->period_id !== $nachVersion->period_id) {
            $validator->errors()->add('to_trip_id', 'Die Fahrten gehören zu verschiedenen Fahrplanperioden.');

            return;
        }

        if ($vonVersion->day_type !== $nachVersion->day_type) {
            $validator->errors()->add('to_trip_id', 'Die Fahrten gehören zu verschiedenen Fahrplantypen.');

            return;
        }

        // Geprüft wird die **Haltestelle**, nicht der einzelne Halt. An einer Endstelle liegen
        // Ankunft und Abfahrt oft auf verschiedenen Punkten: An „Herrenkrug" enden Fahrten auf
        // dem einen Bahnsteig und beginnen 72 m weiter auf dem anderen. Netzweit sind 64 von
        // 104 Endstellen so gebaut — auf Halt-Identität zu prüfen hieße, an über der Hälfte
        // aller Fahrt-Endpunkte keinen Anschluss zulassen zu können (KURSE §3.1).
        $gruppen = app(StopGroupService::class);

        $ende = $von->last_stop_id === null ? null : $gruppen->groupIdFor($von->last_stop_id);
        $anfang = $nach->first_stop_id === null ? null : $gruppen->groupIdFor($nach->first_stop_id);

        if ($ende === null || $anfang === null || $ende !== $anfang) {
            $validator->errors()->add(
                'to_trip_id',
                'Die zweite Fahrt beginnt nicht an der Haltestelle, an der die erste endet. '
                .'Gehören die beiden Bahnsteige zusammen, sind sie unter „Haltestellen" derselben Haltestelle zuzuordnen.',
            );

            return;
        }

        // Überschneiden sich die Gültigkeiten an keinem Tag, könnte der Anschluss nie
        // zustande kommen — die beiden Fahrpläne standen nie gleichzeitig in Kraft.
        if (! $this->gueltigkeitenUeberschneidenSich($vonVersion->id, $nachVersion->id)) {
            $validator->errors()->add(
                'to_trip_id',
                'Die Fahrplan-Versionen der beiden Fahrten gelten an keinem gemeinsamen Tag.',
            );

            return;
        }

        $links = app(TripLinkService::class);

        if ($links->wouldCreateCycle($von->id, $nach->id)) {
            $validator->errors()->add('to_trip_id', 'Dieser Anschluss würde die Kette zu einem Ring schließen.');

            return;
        }

        $wendezeit = $links->turnaroundSeconds($von, $nach);

        if ($wendezeit !== null && $wendezeit < 0) {
            $validator->errors()->add(
                'to_trip_id',
                'Die zweite Fahrt beginnt vor dem Ende der ersten — das ergibt eine negative Wendezeit.',
            );
        }
    }

    /**
     * Überschneiden sich die beobachteten Gültigkeiten zweier Versionen an mindestens einem Tag?
     *
     * Bewusst in PHP statt als SQL-Join: Die `date`-Spalten tragen unter SQLite eine Uhrzeit,
     * unter PostgreSQL nicht (siehe CLAUDE.md). Ein Spaltenvergleich in SQL wäre damit von der
     * Schreibweise abhängig. Je Version stehen ohnehin nur wenige Intervalle an.
     */
    private function gueltigkeitenUeberschneidenSich(int $vonVersionId, int $nachVersionId): bool
    {
        $intervalle = LineVersionInterval::query()
            ->whereIn('line_version_id', [$vonVersionId, $nachVersionId])
            ->get()
            ->groupBy('line_version_id');

        $a = $intervalle->get($vonVersionId, collect());
        $b = $intervalle->get($nachVersionId, collect());

        foreach ($a as $links) {
            foreach ($b as $rechts) {
                if ($links->valid_from <= $rechts->valid_to && $rechts->valid_from <= $links->valid_to) {
                    return true;
                }
            }
        }

        return false;
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
