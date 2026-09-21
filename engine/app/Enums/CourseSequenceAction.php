<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Die beiden Richtungen des Mengen-Laufs in der Fahrplan-Matrix.
 *
 * **Nicht spiegelbildlich zu {@see AutoLinkAction}, und das ist Absicht:** `Clear` nimmt das
 * Etikett ab und lässt die Kette stehen, `Unlink` zerschneidet die Kette und lässt das Etikett
 * stehen. Beides folgt dem Bestand — ein Umlauf muss keine durchgehende Kette sein, und eine
 * gelöste Nummer ist nicht verbraucht.
 */
enum CourseSequenceAction: string
{
    case Assign = 'assign';
    case Clear = 'clear';

    public function label(): string
    {
        return match ($this) {
            self::Assign => 'Kursnummern fortschreiben',
            self::Clear => 'Kursnummern entfernen',
        };
    }
}
