<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Art einer Umlauf-Entscheidung (KURSE §3).
 *
 * `Start` und `End` sind der **Betriebsfahrt-Fall** — eine Kette, die bewusst ohne Anschluss
 * beginnt oder endet (Aus-/Einrücken, kommt besonders morgens vor). Sie sind ausdrücklich etwas
 * anderes als eine fehlende Entscheidung: Ohne diese Unterscheidung ließe sich „hier ist
 * wirklich Schluss" nicht von „noch nicht gepflegt" trennen, und der Pflegestand wäre nicht
 * ablesbar.
 */
enum TripLinkKind: string
{
    case Link = 'link';
    case Start = 'start';
    case End = 'end';

    public function label(): string
    {
        return match ($this) {
            self::Link => 'Anschluss',
            self::Start => 'Beginnt hier (Ausrücken)',
            self::End => 'Endet hier (Einrücken)',
        };
    }

    /** Trägt diese Art eine Vorgängerfahrt? */
    public function hasFrom(): bool
    {
        return $this !== self::Start;
    }

    /** Trägt diese Art eine Nachfolgerfahrt? */
    public function hasTo(): bool
    {
        return $this !== self::End;
    }
}
