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

    public function label(): string
    {
        return match ($this) {
            self::Open => 'offen',
            self::Accepted => 'angenommen',
            self::Declined => 'abgelehnt',
        };
    }
}
