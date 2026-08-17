<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Calendar;
use App\Models\CalendarDate;
use App\Models\Route;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use App\Services\ServiceDayResolver;
use App\Services\TripFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TripFilterServiceTest extends TestCase
{
    use RefreshDatabase;

    // 2026-06-22 ist ein Montag, 2026-06-20 ein Samstag.
    private const MONDAY = '2026-06-22';

    private const SATURDAY = '2026-06-20';

    private TripFilterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TripFilterService(new ServiceDayResolver);
    }

    public function test_filter_by_line_returns_only_matching_route(): void
    {
        $line1 = Route::factory()->create(['route_short_name' => '1']);
        $line6 = Route::factory()->create(['route_short_name' => '6']);

        $match = Trip::factory()->create(['route_id' => $line1->route_id]);
        Trip::factory()->create(['route_id' => $line6->route_id]);

        $result = $this->service->filter(['line' => '1']);

        $this->assertCount(1, $result);
        $this->assertSame($match->trip_id, $result->first()->trip_id);
    }

    public function test_filter_by_stop_returns_only_trips_serving_stop(): void
    {
        $stop = Stop::factory()->create();
        $other = Stop::factory()->create();

        $serving = Trip::factory()->create();
        StopTime::factory()->create(['trip_id' => $serving->trip_id, 'stop_id' => $stop->stop_id, 'stop_sequence' => 1]);

        $notServing = Trip::factory()->create();
        StopTime::factory()->create(['trip_id' => $notServing->trip_id, 'stop_id' => $other->stop_id, 'stop_sequence' => 1]);

        $result = $this->service->filter(['stop' => $stop->stop_id]);

        $this->assertCount(1, $result);
        $this->assertSame($serving->trip_id, $result->first()->trip_id);
    }

    public function test_filter_by_date_respects_weekday_pattern(): void
    {
        // Wochenmuster Mo–Fr aktiv.
        $weekday = Calendar::factory()->create(['service_id' => 'WD']);
        $trip = Trip::factory()->create(['service_id' => $weekday->service_id]);

        // Montag → Trip gültig, Samstag → nicht.
        $this->assertCount(1, $this->service->filter(['date' => self::MONDAY]));
        $this->assertCount(0, $this->service->filter(['date' => self::SATURDAY]));

        // Trip-Existenz unabhängig vom Datumsfilter prüfen.
        $this->assertNotNull($trip->fresh());
    }

    public function test_filter_by_date_excludes_service_outside_validity_range(): void
    {
        Calendar::factory()->create([
            'service_id' => 'OLD',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
        Trip::factory()->create(['service_id' => 'OLD']);

        // Montag liegt 2026 — außerhalb des Gültigkeitszeitraums.
        $this->assertCount(0, $this->service->filter(['date' => self::MONDAY]));
    }

    public function test_filter_by_date_includes_calendar_dates_exception_added(): void
    {
        // Wochenmuster nur am Wochenende — Montag regulär inaktiv.
        $weekend = Calendar::factory()->weekend()->create(['service_id' => 'WE']);
        Trip::factory()->create(['service_id' => $weekend->service_id]);

        $this->assertCount(0, $this->service->filter(['date' => self::MONDAY]));

        // Ausnahme: Betrieb am Montag zusätzlich hinzugefügt.
        CalendarDate::factory()->added()->create(['service_id' => 'WE', 'date' => self::MONDAY]);

        $this->assertCount(1, $this->service->filter(['date' => self::MONDAY]));
    }

    public function test_filter_by_date_excludes_calendar_dates_exception_removed(): void
    {
        $weekday = Calendar::factory()->create(['service_id' => 'WD']);
        Trip::factory()->create(['service_id' => $weekday->service_id]);

        $this->assertCount(1, $this->service->filter(['date' => self::MONDAY]));

        // Ausnahme: Betrieb am Montag entfällt (z.B. Feiertag).
        CalendarDate::factory()->removed()->create(['service_id' => 'WD', 'date' => self::MONDAY]);

        $this->assertCount(0, $this->service->filter(['date' => self::MONDAY]));
    }

    public function test_filter_combines_date_line_and_stop(): void
    {
        $weekday = Calendar::factory()->create(['service_id' => 'WD']);
        $line1 = Route::factory()->create(['route_short_name' => '1']);
        $stop = Stop::factory()->create();

        // Vollständiger Treffer.
        $match = Trip::factory()->create(['service_id' => 'WD', 'route_id' => $line1->route_id]);
        StopTime::factory()->create(['trip_id' => $match->trip_id, 'stop_id' => $stop->stop_id, 'stop_sequence' => 1]);

        // Gleiche Linie & Haltestelle, aber falscher Betriebstag.
        $wrongService = Trip::factory()->create(['service_id' => 'OTHER', 'route_id' => $line1->route_id]);
        StopTime::factory()->create(['trip_id' => $wrongService->trip_id, 'stop_id' => $stop->stop_id, 'stop_sequence' => 1]);

        $result = $this->service->filter(['date' => self::MONDAY, 'line' => '1', 'stop' => $stop->stop_id]);

        $this->assertCount(1, $result);
        $this->assertSame($match->trip_id, $result->first()->trip_id);
    }

    public function test_filter_without_criteria_returns_all_trips(): void
    {
        Trip::factory()->count(3)->create();

        $this->assertCount(3, $this->service->filter([]));
    }

    public function test_filter_no_match_returns_empty_collection(): void
    {
        Trip::factory()->create();

        $result = $this->service->filter(['line' => 'does-not-exist']);

        $this->assertTrue($result->isEmpty());
    }
}
