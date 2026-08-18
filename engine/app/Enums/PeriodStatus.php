<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Zustand einer Fahrplanperiode (FAHRPLANPERIODEN §4.1).
 */
enum PeriodStatus: string
{
    case Current = 'current';
    case Frozen = 'frozen';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'laufend',
            self::Frozen => 'eingefroren',
        };
    }
}
