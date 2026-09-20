<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Woher eine Haltestellen-Gruppierung stammt.
 *
 * Der Unterschied ist nicht bloß Herkunftsnachweis: `Manual` **schützt** eine Zuordnung vor der
 * Automatik. Der Namensabgleich zieht einen von Hand zugeordneten Halt nicht zurück in seine
 * Namensgruppe — sonst wäre jede Pflege beim nächsten Import wieder verloren.
 */
enum StopGroupOrigin: string
{
    case Auto = 'auto';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Automatisch (Namensgleichheit)',
            self::Manual => 'Von Hand zugeordnet',
        };
    }
}
