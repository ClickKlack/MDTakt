<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FahrplanTyp;
use Illuminate\Validation\Rule;

/**
 * Filter des Haltestellen-Verzeichnisses.
 */
final class StopGroupListRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleans('only_termini');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'only_termini' => ['nullable', 'boolean'],
            // Nur gemeinsam sinnvoll: Erst beide zusammen benennen einen Fahrplan, und erst
            // dann laesst sich sagen, was an einer Haltestelle noch offen ist.
            'period' => ['nullable', 'integer', 'exists:schedule_periods,id'],
            'day_type' => ['nullable', Rule::enum(FahrplanTyp::class)],
        ];
    }

    public function periodId(): ?int
    {
        $wert = $this->input('period');

        return $wert === null || $wert === '' ? null : (int) $wert;
    }

    public function dayType(): ?FahrplanTyp
    {
        $wert = $this->input('day_type');

        return is_string($wert) && $wert !== '' ? FahrplanTyp::from($wert) : null;
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
