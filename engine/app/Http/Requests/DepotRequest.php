<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Anlegen und Ändern eines Betriebshofs.
 *
 * Der Name ist eindeutig: „Nord" zweimal im Verzeichnis wäre an der Fahrt nicht auseinander zu
 * halten, und die Zuordnung ist genau die Angabe, die stimmen muss.
 */
final class DepotRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleans('active');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $id = $this->route('depot')?->id;

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('depots', 'name')->ignore($id)],
            'short_name' => ['nullable', 'string', 'max:16'],
            // Mehrere: Die Westerhuesener Fahrten ruecken fast immer an der Schleswiger
            // Strasse aus, nicht am Hof selbst.
            'stop_group_ids' => ['nullable', 'array'],
            'stop_group_ids.*' => ['integer', 'exists:stop_groups,id'],
            // Leer = alle Verkehrsmittel. Das ist der Vorgabefall, nicht „keines".
            'modes' => ['nullable', 'array'],
            'modes.*' => ['string', 'in:tram,bus'],
            'active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Einen Betriebshof mit diesem Namen gibt es schon.',
            'modes.*.in' => 'Ein Betriebshof nimmt Tram oder Bus auf — etwas anderes gibt es im Netz nicht.',
        ];
    }

    /**
     * Die Werte in Schreibform.
     *
     * `modes` wird zur leeren Liste normalisiert, nicht zu `null`: Beide bedeuten „alle", und
     * zwei Schreibweisen für dieselbe Aussage laufen früher oder später auseinander.
     *
     * @return array<string, mixed>
     */
    public function daten(): array
    {
        /** @var array<int, string> $modi */
        $modi = array_values(array_unique((array) ($this->input('modes') ?? [])));

        return [
            'name' => trim((string) $this->input('name')),
            'short_name' => $this->leerZuNull('short_name'),
            'modes' => $modi,
            'active' => $this->input('active') === null ? true : $this->boolean('active'),
            'note' => $this->leerZuNull('note'),
        ];
    }

    /**
     * Die zugeordneten Haltestellen, entdoppelt.
     *
     * @return array<int, int>
     */
    public function stopGroupIds(): array
    {
        /** @var array<int, mixed> $werte */
        $werte = (array) ($this->input('stop_group_ids') ?? []);

        return array_values(array_unique(array_map(static fn ($id): int => (int) $id, $werte)));
    }

    private function leerZuNull(string $feld): ?string
    {
        $wert = $this->input($feld);

        if (! is_string($wert)) {
            return null;
        }

        $wert = trim($wert);

        return $wert === '' ? null : $wert;
    }
}
