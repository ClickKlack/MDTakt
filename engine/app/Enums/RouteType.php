<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * GTFS route_type, eingeschränkt auf die im MVB-Netz vorkommenden Verkehrsmittel.
 *
 * Der rohe Integer kommt aus dem GTFS-Feed; `mode()` liefert die maschinenlesbare
 * Kurzform für die API. Unbekannte/zukünftige route_types werden über
 * {@see self::modeFor()} bewusst auf `other` abgebildet statt einen Fehler zu werfen.
 */
enum RouteType: int
{
    case Tram = 0;
    case Bus = 3;

    /**
     * Semantischer Modus-Slug für die API-Ausgabe.
     */
    public function mode(): string
    {
        return match ($this) {
            self::Tram => 'tram',
            self::Bus => 'bus',
        };
    }

    /**
     * Bildet einen beliebigen GTFS route_type auf einen Modus-Slug ab.
     * Nicht abgedeckte Typen ergeben `other` (robust gegen Feed-Erweiterungen).
     */
    public static function modeFor(int $routeType): string
    {
        return self::tryFrom($routeType)?->mode() ?? 'other';
    }
}
