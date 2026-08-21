<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Validiert die Annahme eines Periodenwechsel-Vorschlags (FAHRPLANPERIODEN §4.3).
 * Der Beginn steht bereits fest — er ist der beobachtete Wechseltag des Vorschlags.
 */
final class PeriodChangeAcceptRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
        ];
    }

    public function label(): string
    {
        return (string) $this->validated('label');
    }
}
