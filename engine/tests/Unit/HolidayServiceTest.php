<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\HolidayService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class HolidayServiceTest extends TestCase
{
    private HolidayService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new HolidayService;
    }

    public function test_fixed_holidays_for_sachsen_anhalt(): void
    {
        $days = $this->service->forYear(2026);

        $this->assertSame('Neujahr', $days['2026-01-01'] ?? null);
        $this->assertSame('Heilige Drei Könige', $days['2026-01-06'] ?? null);
        $this->assertSame('Tag der Arbeit', $days['2026-05-01'] ?? null);
        $this->assertSame('Tag der Deutschen Einheit', $days['2026-10-03'] ?? null);
        $this->assertSame('Reformationstag', $days['2026-10-31'] ?? null);
        $this->assertSame('2. Weihnachtstag', $days['2026-12-26'] ?? null);
    }

    public function test_easter_relative_holidays_2026(): void
    {
        // Ostersonntag 2026 = 05.04.
        $days = $this->service->forYear(2026);

        $this->assertSame('Karfreitag', $days['2026-04-03'] ?? null);
        $this->assertSame('Ostermontag', $days['2026-04-06'] ?? null);
        $this->assertSame('Christi Himmelfahrt', $days['2026-05-14'] ?? null);
        $this->assertSame('Pfingstmontag', $days['2026-05-25'] ?? null);
    }

    public function test_is_holiday(): void
    {
        $this->assertTrue($this->service->isHoliday(CarbonImmutable::parse('2026-05-01')));
        $this->assertTrue($this->service->isHoliday(CarbonImmutable::parse('2026-04-03')));
        $this->assertFalse($this->service->isHoliday(CarbonImmutable::parse('2026-05-02')));
    }
}
