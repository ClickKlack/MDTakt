<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Berechnet die gesetzlichen Feiertage in Sachsen-Anhalt je Jahr.
 *
 * Feste Tage + oster-relative Tage (Osterdatum über den Computus/Gauß-Algorithmus).
 * Wird nicht persistiert — die Regel ist Gesetz und stabil.
 */
final class HolidayService
{
    /**
     * Liefert die Feiertage eines Jahres als `Y-m-d` => Name.
     *
     * @return array<string, string>
     */
    public function forYear(int $year): array
    {
        $easter = $this->easterSunday($year);

        $days = [
            sprintf('%04d-01-01', $year) => 'Neujahr',
            sprintf('%04d-01-06', $year) => 'Heilige Drei Könige',
            sprintf('%04d-05-01', $year) => 'Tag der Arbeit',
            sprintf('%04d-10-03', $year) => 'Tag der Deutschen Einheit',
            sprintf('%04d-10-31', $year) => 'Reformationstag',
            sprintf('%04d-12-25', $year) => '1. Weihnachtstag',
            sprintf('%04d-12-26', $year) => '2. Weihnachtstag',
            $easter->subDays(2)->toDateString() => 'Karfreitag',
            $easter->addDay()->toDateString() => 'Ostermontag',
            $easter->addDays(39)->toDateString() => 'Christi Himmelfahrt',
            $easter->addDays(50)->toDateString() => 'Pfingstmontag',
        ];

        ksort($days);

        return $days;
    }

    /**
     * Ist das Datum ein Feiertag in Sachsen-Anhalt?
     */
    public function isHoliday(CarbonImmutable $date): bool
    {
        return isset($this->forYear((int) $date->year)[$date->toDateString()]);
    }

    /**
     * Ostersonntag eines Jahres (Anonymous-Gregorian-/Gauß-Algorithmus, Computus).
     */
    private function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0);
    }
}
