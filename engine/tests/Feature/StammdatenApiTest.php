<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\Route;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StammdatenApiTest extends TestCase
{
    use RefreshDatabase;

    // 2026-06-22 ist ein Montag.
    private const MONDAY = '2026-06-22';

    public function test_lines_endpoint_returns_all_routes(): void
    {
        Route::factory()->tram()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        Route::factory()->bus()->create(['route_id' => 'R73', 'route_short_name' => '73']);

        $response = $this->getJson('/api/v1/lines');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['route_short_name' => '1', 'route_type' => 0, 'mode' => 'tram'])
            ->assertJsonFragment(['route_short_name' => '73', 'route_type' => 3, 'mode' => 'bus']);
    }

    /**
     * Dieselbe Linienbezeichnung auf zwei GTFS-Routen (N2 als Tram und als Bus während
     * des Schienenersatzverkehrs) darf im Verzeichnis nur einen Eintrag ergeben — sonst
     * stünde dieselbe Linie doppelt in der Auswahl, beide Male mit identischem Inhalt.
     */
    public function test_line_served_by_two_routes_appears_once(): void
    {
        $tram = Route::factory()->tram()->create(['route_id' => 'R-TRAM', 'route_short_name' => 'N2']);
        $bus = Route::factory()->bus()->create(['route_id' => 'R-BUS', 'route_short_name' => 'N2']);

        // Die Bus-Route hat mehr Fahrten → sie prägt die Linie (route_type/mode).
        Trip::factory()->create(['trip_id' => 'T-TRAM', 'route_id' => $tram->route_id]);
        Trip::factory()->count(2)->sequence(
            ['trip_id' => 'T-BUS-1'],
            ['trip_id' => 'T-BUS-2'],
        )->create(['route_id' => $bus->route_id]);

        $response = $this->getJson('/api/v1/lines');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.route_short_name', 'N2')
            ->assertJsonPath('data.0.mode', 'bus')
            ->assertJsonPath('data.0.route_type', 3)
            ->assertJsonPath('data.0.modes', ['bus', 'tram'])
            ->assertJsonPath('data.0.route_ids', ['R-BUS', 'R-TRAM']);
    }

    public function test_stops_endpoint_returns_all_stops_ordered_by_name(): void
    {
        Stop::factory()->create(['stop_id' => 'S2', 'stop_name' => 'Alter Markt']);
        Stop::factory()->create(['stop_id' => 'S1', 'stop_name' => 'Hasselbachplatz']);

        $response = $this->getJson('/api/v1/stops');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame('Alter Markt', $response->json('data.0.stop_name'));
    }

    public function test_trips_endpoint_filters_by_date_line_and_stop(): void
    {
        Calendar::factory()->create(['service_id' => 'WD']);
        $line = Route::factory()->tram()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        $stop = Stop::factory()->create(['stop_id' => 'S1', 'stop_name' => 'Hasselbachplatz']);

        $match = Trip::factory()->create(['trip_id' => 'T1', 'service_id' => 'WD', 'route_id' => $line->route_id]);
        StopTime::factory()->create(['trip_id' => 'T1', 'stop_id' => $stop->stop_id, 'stop_sequence' => 1]);

        // Andere Linie — darf nicht zurückkommen.
        $other = Route::factory()->tram()->create(['route_id' => 'R6', 'route_short_name' => '6']);
        $otherTrip = Trip::factory()->create(['trip_id' => 'T2', 'service_id' => 'WD', 'route_id' => $other->route_id]);
        StopTime::factory()->create(['trip_id' => 'T2', 'stop_id' => 'S1', 'stop_sequence' => 1]);

        $response = $this->getJson('/api/v1/trips?date='.self::MONDAY.'&line=1&stop=S1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['trip_id' => 'T1', 'route_short_name' => '1', 'route_type' => 0]);

        $this->assertNotNull($match->fresh());
        $this->assertNotNull($otherTrip->fresh());
    }

    public function test_trips_endpoint_rejects_invalid_date_format(): void
    {
        $response = $this->getJson('/api/v1/trips?date=22.06.2026');

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_trips_endpoint_without_filters_returns_all_trips(): void
    {
        Trip::factory()->count(2)->create();

        $response = $this->getJson('/api/v1/trips');

        $response->assertOk()->assertJsonCount(2, 'data');
    }
}
