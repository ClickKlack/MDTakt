<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\ConsolidatedStop;
use App\Models\ConsolidatedTrip;
use App\Models\Route;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CLI-Einstieg in die Konsolidierung — gebraucht nach einem Deployment, das die
 * Konsolidat-Tabellen neu anlegt: Bis zum nächsten Import sähen die öffentlichen Endpunkte
 * sonst leer aus, obwohl Roh-Daten vorliegen.
 */
final class ConsolidateCommandTest extends TestCase
{
    use RefreshDatabase;

    private function rohbestand(): void
    {
        Stop::factory()->create(['stop_id' => 'S1', 'stop_name' => 'Alpha', 'lat' => 52.14, 'lon' => 11.63]);
        Stop::factory()->create(['stop_id' => 'S2', 'stop_name' => 'Beta', 'lat' => 52.15, 'lon' => 11.64]);

        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        Calendar::factory()->create([
            'service_id' => 'W',
            'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true,
            'friday' => true, 'saturday' => false, 'sunday' => false,
            'start_date' => '2026-08-17', 'end_date' => '2026-08-21',
        ]);
        Trip::factory()->create(['trip_id' => 'T1', 'route_id' => $line->route_id, 'service_id' => 'W']);

        foreach ([['S1', '07:00:00', 1], ['S2', '07:20:00', 2]] as [$stop, $zeit, $seq]) {
            StopTime::factory()->create([
                'trip_id' => 'T1', 'stop_id' => $stop, 'stop_sequence' => $seq,
                'departure_time' => $zeit, 'arrival_time' => $zeit,
            ]);
        }
    }

    public function test_command_builds_the_consolidate_from_existing_raw_data(): void
    {
        $this->rohbestand();

        $this->artisan('schedule:consolidate')->assertSuccessful();

        $this->assertSame(2, ConsolidatedStop::query()->count());
        $this->assertSame(1, ConsolidatedTrip::query()->count());
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->rohbestand();
        $this->artisan('schedule:consolidate')->assertSuccessful();

        $this->artisan('schedule:consolidate')->assertSuccessful();

        $this->assertSame(1, ConsolidatedTrip::query()->count());
    }

    public function test_without_raw_data_the_command_warns_instead_of_failing(): void
    {
        // Ein leerer Bestand ist kein Fehler — er heißt nur: erst importieren.
        $this->artisan('schedule:consolidate')
            ->expectsOutputToContain('Kein Roh-Bestand gefunden')
            ->assertSuccessful();
    }
}
