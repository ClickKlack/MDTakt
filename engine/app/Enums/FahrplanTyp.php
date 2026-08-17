<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Fahrplantyp (Betriebstag-Klasse) — innerhalb einer Fahrplanperiode kann jede Linie
 * je Typ einen eigenen Fahrplan/eine eigene Version haben.
 */
enum FahrplanTyp: string
{
    case MoFrNormal = 'mo_fr';
    case MoFrFerien = 'mo_fr_ferien';
    case Sa = 'sa';
    case SoFeiertag = 'so_feiertag';

    public function label(): string
    {
        return match ($this) {
            self::MoFrNormal => 'Mo-Fr',
            self::MoFrFerien => 'Mo-Fr (Ferien)',
            self::Sa => 'Samstag',
            self::SoFeiertag => 'So + Feiertage',
        };
    }
}
