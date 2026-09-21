<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\Route;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use App\Models\User;
use App\Services\ScheduleVersionService;
use App\Services\TripSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Abdeckungs-Anzeige des Konsolidats (FAHRPLANPERIODEN Phase C).
 *
 * Kern der Anzeige ist, Lücken **auszuweisen** statt zu verschweigen — und dabei nicht jeden
 * Wochenendabstand für eine Lücke zu halten.
 */
final class CoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Stop::factory()->create(['stop_id' => 'A', 'stop_name' => 'Alpha', 'lat' => 52.14, 'lon' => 11.63]);
        Stop::factory()->create(['stop_id' => 'B', 'stop_name' => 'Beta', 'lat' => 52.15, 'lon' => 11.64]);
    }

    private function adminToken(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
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
                'stop_id' => $i === 0 ? 'A' : 'B',
                'stop_sequence' => $i + 1,
                'departure_time' => $zeit,
                'arrival_time' => $zeit,
            ]);
        }
    }

    private function konsolidieren(): void
    {
        app(TripSignatureService::class)->rebuild();
        app(ScheduleVersionService::class)->updateFromCurrentImport();
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/admin/coverage')->assertStatus(401);
    }

    public function test_saturdays_across_several_weeks_count_as_continuous_coverage(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);

        // Nur samstags — zwischen zwei Samstagen liegen sechs Tage ohne einen einzigen
        // Samstag. Nach Kalendertagen gerechnet wären das lauter Ein-Tages-Abschnitte
        // mit Lücken dazwischen.
        Calendar::factory()->create([
            'service_id' => 'SA',
            'monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false,
            'friday' => false, 'saturday' => true, 'sunday' => false,
            // Einen Tag ueber den letzten Samstag hinaus: Der letzte Tag des Feed-Fensters
            // wird nicht ausgewertet (FAHRPLANPERIODEN §10), und hier geht es um die
            // Samstags-Kette, nicht um den Fensterrand.
            'start_date' => '2026-08-15', 'end_date' => '2026-09-06',
        ]);
        $this->fahrt('T1', 'SA', $line->route_id, ['07:00:00', '07:20:00']);

        $this->konsolidieren();

        $daten = $this->withToken($this->adminToken())
            ->getJson('/api/v1/admin/coverage')
            ->assertOk()
            ->json('data');

        $sa = collect($daten['lines'][0]['day_types'])->firstWhere('day_type', 'sa');

        $this->assertCount(1, $sa['ranges'], 'Samstage in Folge sind ein durchgehender Abschnitt');
        $this->assertSame([], $sa['gaps'], 'Zwischen zwei Samstagen klafft keine Lücke');
        $this->assertSame('2026-08-15', $sa['ranges'][0]['from']);
        $this->assertSame('2026-09-05', $sa['ranges'][0]['to']);
    }

    public function test_a_missed_import_leaves_a_visible_gap(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);

        // Erster Import: Fenster 17.–22.08., beobachtet bis zum 21.08. — der letzte Tag des
        // Fensters wird nicht ausgewertet (FAHRPLANPERIODEN §10).
        Calendar::factory()->create([
            'service_id' => 'W1',
            'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true,
            'friday' => true, 'saturday' => false, 'sunday' => false,
            'start_date' => '2026-08-17', 'end_date' => '2026-08-22',
        ]);
        $this->fahrt('T1', 'W1', $line->route_id, ['07:00:00', '07:20:00']);
        $this->konsolidieren();

        // Der Lauf der Folgewoche faellt aus; erst der uebernaechste laeuft wieder. Sein
        // Fenster beginnt am 31.08. — die Woche dazwischen hat niemand je gesehen und ist
        // endgueltig verloren. Genau das muss die Anzeige sagen, statt es zu glaetten.
        StopTime::query()->delete();
        Trip::query()->delete();
        Calendar::query()->delete();
        Calendar::factory()->create([
            'service_id' => 'W2',
            'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true,
            'friday' => true, 'saturday' => false, 'sunday' => false,
            'start_date' => '2026-08-31', 'end_date' => '2026-09-04',
        ]);
        $this->fahrt('T2', 'W2', $line->route_id, ['07:00:00', '07:20:00']);
        $this->konsolidieren();

        $daten = $this->withToken($this->adminToken())->getJson('/api/v1/admin/coverage')->json('data');
        $moFr = collect($daten['lines'][0]['day_types'])->firstWhere('day_type', 'mo_fr');

        $this->assertCount(2, $moFr['ranges'], 'Zwei getrennte Beobachtungszeitraeume');
        $this->assertCount(1, $moFr['gaps'], 'Die verpasste Woche muss sichtbar sein');
        $this->assertSame('2026-08-22', $moFr['gaps'][0]['from']);
        $this->assertSame('2026-08-30', $moFr['gaps'][0]['to']);
        $this->assertSame(5, $moFr['gaps'][0]['days'], 'Fuenf Werktage fehlen — Wochenenden zaehlen nicht mit');
    }

    public function test_totals_report_content_not_just_versions(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        Calendar::factory()->create([
            'service_id' => 'W',
            'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true,
            'friday' => true, 'saturday' => false, 'sunday' => false,
            'start_date' => '2026-08-17', 'end_date' => '2026-08-21',
        ]);
        $this->fahrt('T1', 'W', $line->route_id, ['07:00:00', '07:20:00']);

        $this->konsolidieren();

        $daten = $this->withToken($this->adminToken())->getJson('/api/v1/admin/coverage')->json('data');

        $this->assertSame(1, $daten['totals']['lines']);
        $this->assertSame(1, $daten['totals']['consolidated_trips']);
        $this->assertSame(0, $daten['totals']['versions_without_content']);
        $this->assertSame('2026-08-17', $daten['window']['from']);
    }
}
