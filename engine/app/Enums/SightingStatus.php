<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bearbeitungsstand einer Sichtung aus MDKursTracker.
 *
 * `confirmed` setzt die Engine selbst: Der gesichtete Kurs hängt schon an der Fahrt, es gibt
 * nichts zu entscheiden. Die Sichtung zählt als Beleg, landet aber nicht in der Warteschlange.
 */
enum SightingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'offen',
            self::Confirmed => 'bestätigt',
            self::Accepted => 'angenommen',
            self::Rejected => 'abgelehnt',
        };
    }
}
