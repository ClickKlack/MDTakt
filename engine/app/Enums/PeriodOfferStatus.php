<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Zustand eines Periodenwechsel-Vorschlags (FAHRPLANPERIODEN §4.3).
 */
enum PeriodOfferStatus: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Declined = 'declined';

    /**
     * Von einer Periodengrenze erledigt, ohne selbst entschieden zu sein: Der vorgeschlagene
     * Wechsel ist nach dem Neuaufbau keiner mehr, weil eine (frühere) Periode ihn schon trägt.
     * Fall 02.11.2026: Werktags verdeckte der Ferienfahrplan den Wechsel, sichtbar wurde er nur
     * am ersten Wochenende (07./08.11.).
     */
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'offen',
            self::Accepted => 'angenommen',
            self::Declined => 'abgelehnt',
            self::Superseded => 'durch Periode erfasst',
        };
    }
}
