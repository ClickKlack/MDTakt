<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Filter des Haltestellen-Verzeichnisses.
 */
final class StopGroupListRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'only_termini' => ['nullable', 'boolean'],
        ];
    }

    public function suche(): ?string
    {
        $wert = $this->query('q');

        return is_string($wert) && $wert !== '' ? $wert : null;
    }

    /**
     * Nur Haltestellen, an denen Fahrten beginnen oder enden. Vorgabe ist `false` — die
     * Zuordnungs-Pflege braucht auch die Durchfahrts-Halte, die Umlauf-Pflege nicht.
     */
    public function onlyTermini(): bool
    {
        return $this->boolean('only_termini');
    }
}
