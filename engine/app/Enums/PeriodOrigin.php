<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Woher eine Fahrplanperiode stammt (FAHRPLANPERIODEN §4.1/§4.3).
 *
 * `Bootstrap` ist über das Konzept hinaus nötig: Die Konsolidierung braucht eine Periode,
 * bevor ein Admin eine anlegen konnte. Der erste Lauf legt sie deshalb selbst an — sichtbar
 * als solche markiert, damit sie nicht mit einer kuratierten Periode verwechselt wird.
 */
enum PeriodOrigin: string
{
    case Admin = 'admin';
    case Offer = 'offer';
    case Bootstrap = 'bootstrap';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'vom Admin angelegt',
            self::Offer => 'Systemvorschlag angenommen',
            self::Bootstrap => 'automatisch beim ersten Import',
        };
    }
}
