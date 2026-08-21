<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\ConsolidatedStop;
use App\Models\LineColor;
use App\Models\Route;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use App\Services\ScheduleVersionService;
use App\Services\TripSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Öffentliche Endpunkte auf dem Konsolidat (FAHRPLANPERIODEN Phase C).
 *
 * Der eigentliche Gewinn: Antworten sind nicht mehr an das aktuelle Feed-Fenster gebunden.
 * Ein Fahrplan, den der letzte Import nicht mehr enthält, bleibt abrufbar.
 */
final class ConsolidatedApiTest extends TestCase
{
    use RefreshDatabase;

    private const MONTAG = '2026-08-17';

    private const SPAETERER_MONTAG = '2026-08-31';

    protected function setUp(): void
    {
        parent::setUp();

        Stop::factory()->create(['stop_id' => 'S1', 'stop_name' => 'Kannenstieg', 'lat' => 52.1600000, 'lon' => 11.6200000]);
        Stop::factory()->create(['stop_id' => 'S2', 'stop_name' => 'Sudenburg', 'lat' => 52.1100000, 'lon' => 11.6100000]);
    }

    /**
     * @param  array<int, string>  $zeiten
     */
    private function fahrt(string $tripId, string $serviceId, string $routeId, array $zeiten): void
    {
        Trip::factory()->create(['trip_id' => $tripId, 'route_id' => $routeId, 'service_id' => $serviceId]);

        foreach ($zeiten as $i => $zeit) {
            StopTime::factory()->create([
                'trip_id' => $tripId,
                'stop_id' => $i === 0 ? 'S1' : 'S2',
                'stop_sequence' => $i + 1,
                'departure_time' => $zeit,
                'arrival_time' => $zeit,
            ]);
        }
    }

    private function werktagsService(string $serviceId, string $von, string $bis): void
    {
        Calendar::factory()->create([
            'service_id' => $serviceId,
            'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true,
            'friday' => true, 'saturday' => false, 'sunday' => false,
            'start_date' => $von, 'end_date' => $bis,
        ]);
    }

    private function konsolidieren(): void
    {
        app(TripSignatureService::class)->rebuild();
        app(ScheduleVersionService::class)->updateFromCurrentImport();
    }

    private function ersterImport(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1', 'route_type' => 0]);
        $this->werktagsService('W1', self::MONTAG, '2026-08-21');
        $this->fahrt('T1', 'W1', $line->route_id, ['07:00:00', '07:25:00']);
        $this->konsolidieren();
    }

    public function test_consolidated_is_the_default_source(): void
    {
        $this->ersterImport();

        $this->getJson('/api/v1/lines')
            ->assertOk()
            ->assertJsonPath('meta.source', 'consolidated')
            ->assertJsonPath('data.0.route_short_name', '1')
            ->assertJsonPath('data.0.mode', 'tram');

        $this->getJson('/api/v1/trips')
            ->assertOk()
            ->assertJsonPath('meta.source', 'consolidated')
            ->assertJsonCount(1, 'data');
    }

    public function test_raw_source_stays_available_for_the_admin(): void
    {
        $this->ersterImport();

        // Der Roh-Bestand trägt die GTFS-Begriffe, das Konsolidat die dauerhafte Signatur.
        $this->getJson('/api/v1/trips?source=raw')
            ->assertOk()
            ->assertJsonPath('meta.source', 'raw')
            ->assertJsonPath('data.0.trip_id', 'T1')
            ->assertJsonMissingPath('data.0.signature');

        $this->getJson('/api/v1/trips')
            ->assertOk()
            ->assertJsonMissingPath('data.0.trip_id')
            ->assertJsonPath('data.0.version_no', 1);
    }

    public function test_an_unknown_source_is_rejected(): void
    {
        $this->getJson('/api/v1/trips?source=erfunden')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_a_schedule_the_current_feed_no_longer_contains_stays_available(): void
    {
        $this->ersterImport();

        // Nächster Import: verschobenes Fenster, geänderte Zeiten. Der alte Fahrplan steht
        // im Roh-Bestand nicht mehr — genau dafür gibt es das Konsolidat.
        StopTime::query()->delete();
        Trip::query()->delete();
        Calendar::query()->delete();
        $this->werktagsService('W2', self::SPAETERER_MONTAG, '2026-09-04');
        $this->fahrt('T2', 'W2', 'R1', ['09:00:00', '09:25:00']);
        $this->konsolidieren();

        // Roh: für den alten Betriebstag gibt es nichts mehr.
        $this->getJson('/api/v1/trips?date='.self::MONTAG.'&source=raw')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Konsolidat: der Fahrplan dieses Tages ist weiterhin abrufbar.
        $this->getJson('/api/v1/trips?date='.self::MONTAG)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.departure_time', '07:00:00')
            ->assertJsonPath('data.0.start_stop', 'Kannenstieg')
            ->assertJsonPath('data.0.end_stop', 'Sudenburg');

        // Und der neue Fahrplan steht daneben, nicht darüber.
        $this->getJson('/api/v1/trips?date='.self::SPAETERER_MONTAG)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.departure_time', '09:00:00');
    }

    public function test_a_date_outside_every_observed_interval_yields_nothing(): void
    {
        $this->ersterImport();

        // Ein Datum, das nie beobachtet wurde: Die ehrliche Antwort ist leer — nicht der
        // zufällig zuletzt bekannte Fahrplan.
        $this->getJson('/api/v1/trips?date=2027-03-15')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_line_directory_from_the_consolidate_carries_mode_and_colour(): void
    {
        $this->ersterImport();
        LineColor::query()->create(['route_short_name' => '1', 'color' => '#c9346c']);

        $this->getJson('/api/v1/lines')
            ->assertOk()
            ->assertJsonPath('data.0.route_short_name', '1')
            ->assertJsonPath('data.0.color', '#c9346c')
            ->assertJsonPath('data.0.modes', ['tram'])
            // GTFS-Route-IDs sind eine Eigenschaft des Roh-Bestands und werden pro Build neu
            // vergeben — im Konsolidat gibt es sie bewusst nicht.
            ->assertJsonPath('data.0.route_ids', []);
    }

    public function test_line_trips_grouped_by_start_and_end_from_the_consolidate(): void
    {
        $this->ersterImport();

        $response = $this->getJson('/api/v1/lines/1/trips?day_type=mo_fr')
            ->assertOk()
            ->assertJsonPath('meta.source', 'consolidated')
            ->assertJsonPath('data.line', '1')
            ->assertJsonPath('data.trip_count', 1)
            ->assertJsonPath('data.groups.0.start_stop', 'Kannenstieg')
            ->assertJsonPath('data.groups.0.end_stop', 'Sudenburg');

        // Statt Wochenmuster trägt die Fahrt hier ihre Version und deren beobachtete
        // Gültigkeit — inklusive der Angabe, ob die Grenzen gesichert sind.
        $trip = $response->json('data.groups.0.trips.0');
        $this->assertSame(1, $trip['version_no']);
        $this->assertSame('07:00:00', $trip['departure_time']);
        $this->assertSame(self::MONTAG, $trip['validity'][0]['valid_from']);
        $this->assertFalse($trip['validity'][0]['from_confirmed']);
    }

    public function test_stop_filter_uses_the_consolidated_stop_id(): void
    {
        $this->ersterImport();

        $haltId = (int) ConsolidatedStop::query()->value('id');

        $this->getJson('/api/v1/trips?stop='.$haltId)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/trips?stop=999999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
