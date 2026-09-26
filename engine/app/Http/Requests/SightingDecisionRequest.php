<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Annehmen oder Ablehnen einer oder mehrerer Sichtungen — mehrere, weil der Fahrplan alle
 * Sichtungen einer Fahrt mit derselben Nummer auf einmal entscheidet.
 */
final class SightingDecisionRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'min:1', 'distinct'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function ids(): array
    {
        return array_map('intval', $this->validated()['ids']);
    }
}
