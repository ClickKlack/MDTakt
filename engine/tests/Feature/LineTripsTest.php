<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Route;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LineTripsTest extends TestCase
{
    use RefreshDatabase;

    private function stopTime(string $tripId, string $stopId, int $seq, string $time): void
    {
        StopTime::factory()->create([
            'trip_id' => $tripId,
            'stop_id' => $stopId,
            'stop_sequence' => $seq,
            'departure_time' => $time,
            'arrival_time' => $time,
        ]);
    }

    public function test_line_trips_grouped_by_start_and_end_stop(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        Stop::factory()->create(['stop_id' => 'A', 'stop_name' => 'Alpha']);
        Stop::factory()->create(['stop_id' => 'B', 'stop_name' => 'Beta']);
        Stop::factory()->create(['stop_id' => 'C', 'stop_name' => 'Gamma']);

        // Zwei Fahrten Alpha → Gamma …
        Trip::factory()->create(['trip_id' => 'T1', 'route_id' => $line->route_id]);
        $this->stopTime('T1', 'A', 1, '06:00:00');
        $this->stopTime('T1', 'B', 2, '06:05:00');
        $this->stopTime('T1', 'C', 3, '06:10:00');

        Trip::factory()->create(['trip_id' => 'T3', 'route_id' => $line->route_id]);
        $this->stopTime('T3', 'A', 1, '07:00:00');
        $this->stopTime('T3', 'C', 2, '07:10:00');

        // … und eine Fahrt zurück Gamma → Alpha.
        Trip::factory()->create(['trip_id' => 'T2', 'route_id' => $line->route_id]);
        $this->stopTime('T2', 'C', 1, '06:30:00');
        $this->stopTime('T2', 'A', 2, '06:40:00');

        $response = $this->getJson('/api/v1/lines/1/trips');

        $response->assertOk()
            ->assertJsonPath('data.line', '1')
            ->assertJsonPath('data.trip_count', 3)
            ->assertJsonCount(2, 'data.groups')
            // Größte Gruppe zuerst: Alpha → Gamma (2 Fahrten)
            ->assertJsonPath('data.groups.0.start_stop', 'Alpha')
            ->assertJsonPath('data.groups.0.end_stop', 'Gamma')
            ->assertJsonPath('data.groups.0.trip_count', 2)
            ->assertJsonPath('data.groups.0.trips.0.departure_time', '06:00:00')
            ->assertJsonPath('data.groups.1.start_stop', 'Gamma')
            ->assertJsonPath('data.groups.1.trip_count', 1);
    }

    public function test_unknown_line_returns_empty_groups(): void
    {
        $this->getJson('/api/v1/lines/999/trips')
            ->assertOk()
            ->assertJsonPath('data.trip_count', 0)
            ->assertJsonCount(0, 'data.groups');
    }
}
