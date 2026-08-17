<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Models\SchoolHoliday;
use App\Services\FahrplanTypClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FahrplanTypClassifierTest extends TestCase
{
    use RefreshDatabase;

    private FahrplanTypClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = app(FahrplanTypClassifier::class);
    }

    private function classify(string $date): FahrplanTyp
    {
        return $this->classifier->classify(CarbonImmutable::parse($date));
    }

    public function test_sunday_is_so_feiertag(): void
    {
        // 2026-06-21 ist ein Sonntag.
        $this->assertSame(FahrplanTyp::SoFeiertag, $this->classify('2026-06-21'));
    }

    public function test_saturday_is_sa(): void
    {
        // 2026-06-20 ist ein Samstag.
        $this->assertSame(FahrplanTyp::Sa, $this->classify('2026-06-20'));
    }

    public function test_weekday_holiday_is_so_feiertag(): void
    {
        // 2026-05-01 (Tag der Arbeit) ist ein Freitag — Feiertag schlägt den Werktag.
        $this->assertSame(FahrplanTyp::SoFeiertag, $this->classify('2026-05-01'));
    }

    public function test_normal_weekday_is_mo_fr_normal(): void
    {
        // 2026-06-22 ist ein Montag, kein Feiertag, keine Ferien.
        $this->assertSame(FahrplanTyp::MoFrNormal, $this->classify('2026-06-22'));
    }

    public function test_weekday_in_school_holidays_is_mo_fr_ferien(): void
    {
        SchoolHoliday::query()->create([
            'name' => 'Sommerferien 2026',
            'start_date' => '2026-07-13',
            'end_date' => '2026-08-16',
        ]);

        // 2026-07-15 ist ein Werktag in den Ferien.
        $this->assertSame(FahrplanTyp::MoFrFerien, $this->classify('2026-07-15'));
        // Außerhalb der Ferien bleibt es normal.
        $this->assertSame(FahrplanTyp::MoFrNormal, $this->classify('2026-06-22'));
    }
}
