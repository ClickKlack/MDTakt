<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TripLinkKind;
use App\Models\TripLink;
use Illuminate\Validation\Validator;

/**
 * Einen bestehenden Anschluss als Durchlauf oder als Wende kennzeichnen (KURSE §2 K10).
 *
 * Eigener Weg neben dem Anlegen: Der Bestand vor K10 trägt das Merkmal nicht, und ein Fehlgriff
 * muss sich zurücknehmen lassen, ohne den Anschluss zu lösen — daran hängt die Kursnummer.
 */
final class TripLinkThroughRunRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'through_run' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->link()->kind !== TripLinkKind::Link) {
                $validator->errors()->add(
                    'through_run',
                    'Nur ein Anschluss kann ein Durchlauf sein — beim Aus- oder Einrücken fährt das Fahrzeug nicht weiter.',
                );
            }
        });
    }

    public function link(): TripLink
    {
        /** @var TripLink $link */
        $link = $this->route('tripLink');

        return $link;
    }

    public function throughRun(): bool
    {
        return $this->boolean('through_run');
    }
}
