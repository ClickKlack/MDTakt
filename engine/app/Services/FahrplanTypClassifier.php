<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use App\Models\SchoolHoliday;
use Carbon\CarbonImmutable;

/**
 * Klassifiziert ein Kalenderdatum in einen Fahrplantyp (Betriebstag-Klasse).
 *
 *   Feiertag?                  → So + Feiertage
 *   Sonntag?                   → So + Feiertage
 *   Samstag?                   → Sa
 *   Mo-Fr & in Schulferien?    → Mo-Fr Ferien
 *   sonst                      → Mo-Fr normal
 */
final class FahrplanTypClassifier
{
    public function __construct(private readonly HolidayService $holidays) {}

    public function classify(CarbonImmutable $date): FahrplanTyp
    {
        if ($this->holidays->isHoliday($date)) {
            return FahrplanTyp::SoFeiertag;
        }

        if ($date->isSunday()) {
            return FahrplanTyp::SoFeiertag;
        }

        if ($date->isSaturday()) {
            return FahrplanTyp::Sa;
        }

        return $this->isSchoolHoliday($date) ? FahrplanTyp::MoFrFerien : FahrplanTyp::MoFrNormal;
    }

    private function isSchoolHoliday(CarbonImmutable $date): bool
    {
        return SchoolHoliday::query()
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->exists();
    }
}
