<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ergebnis der Zuordnung einer Sichtung zu einer Fahrt des Konsolidats.
 *
 * - `matched`: Signatur-Treffer in der Version, die am Betriebstag der Sichtung gilt.
 * - `matched_next_version`: Treffer erst in der **nächsten** Version der Linie. Der Tracker kennt
 *   einen geänderten Laufweg über HAFAS sofort, der Feed oft erst eine Woche später — die neue
 *   Version beginnt dann nach dem Tag der Sichtung. Nie automatisch bestätigt.
 * - `waiting`: Noch kein Treffer; nach dem nächsten Import wird erneut gesucht.
 * - `no_trip`: Auch nach mehreren Importen kein Treffer (Betriebsfahrt, Abweichung im Laufweg).
 * - `ambiguous`: Mehrere Fahrten tragen dieselbe Signatur — keine wird geraten.
 */
enum SightingMatch: string
{
    case Matched = 'matched';
    case MatchedNextVersion = 'matched_next_version';
    case Waiting = 'waiting';
    case NoTrip = 'no_trip';
    case Ambiguous = 'ambiguous';

    public function hasTrip(): bool
    {
        return $this === self::Matched || $this === self::MatchedNextVersion;
    }
}
