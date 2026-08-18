<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\CalendarDate;
use App\Models\LineVersion;
use App\Models\Route;
use App\Models\SchedulePeriod;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use App\Models\TripSignature;
use App\Services\ScheduleVersionService;
use App\Services\TripSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ScheduleVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Stop::factory()->create(['stop_id' => 'A', 'stop_name' => 'Alpha']);
        Stop::factory()->create(['stop_id' => 'B', 'stop_name' => 'Beta']);
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

    private function werktagsService(string $serviceId, string $von, string $bis): void
    {
        Calendar::factory()->create([
            'service_id' => $serviceId,
            'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true,
            'friday' => true, 'saturday' => false, 'sunday' => false,
            'start_date' => $von, 'end_date' => $bis,
        ]);
    }

    private function konsolidieren(): array
    {
        app(TripSignatureService::class)->rebuild();

        return app(ScheduleVersionService::class)->updateFromCurrentImport();
    }

    public function test_signature_is_stable_across_reimport_with_new_trip_ids(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        $this->werktagsService('S1', '2026-08-17', '2026-08-21');
        $this->fahrt('ALT-1', 'S1', $line->route_id, ['07:00:00', '07:20:00']);

        app(TripSignatureService::class)->rebuild();
        $vorher = TripSignature::query()->where('trip_id', 'ALT-1')->value('signature');

        // Re-Import: gtfs.de vergibt neue Surrogat-IDs, der Fahrplan ist identisch.
        StopTime::query()->delete();
        Trip::query()->delete();
        $this->fahrt('NEU-1', 'S1', $line->route_id, ['07:00:00', '07:20:00']);

        app(TripSignatureService::class)->rebuild();
        $nachher = TripSignature::query()->where('trip_id', 'NEU-1')->value('signature');

        $this->assertSame($vorher, $nachher, 'Signatur muss den Wechsel der trip_id überleben');
    }

    public function test_daily_service_gets_one_signature_per_day_type(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        Calendar::factory()->create([
            'service_id' => 'TAEGLICH',
            'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true,
            'friday' => true, 'saturday' => true, 'sunday' => true,
            'start_date' => '2026-08-17', 'end_date' => '2026-08-23',
        ]);
        $this->fahrt('T1', 'TAEGLICH', $line->route_id, ['07:00:00', '07:20:00']);

        app(TripSignatureService::class)->rebuild();

        // Fenster 17.-23.08. enthält Mo-Fr, Sa und So — Ferien sind nicht gepflegt.
        $typen = TripSignature::query()->where('trip_id', 'T1')->pluck('day_type')->map->value->sort()->values()->all();
        $this->assertSame(['mo_fr', 'sa', 'so_feiertag'], $typen);

        // Gleiche Zeiten, aber je Typ eine eigene Identität.
        $this->assertCount(3, TripSignature::query()->where('trip_id', 'T1')->pluck('signature')->unique());
    }

    public function test_unchanged_schedule_yields_one_version_with_open_boundaries(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        $this->werktagsService('S1', '2026-08-17', '2026-08-28');
        $this->fahrt('T1', 'S1', $line->route_id, ['07:00:00', '07:20:00']);

        $result = $this->konsolidieren();

        $this->assertSame(1, $result['versions_created']);
        $version = LineVersion::query()->where('line', '1')->sole();
        $this->assertSame(1, $version->version_no);

        // Ein einziger Abschnitt: beide Grenzen liegen an der Fensterkante, also offen.
        $interval = $version->intervals()->sole();
        $this->assertSame('2026-08-17', $interval->valid_from->toDateString());
        $this->assertSame('2026-08-28', $interval->valid_to->toDateString());
        $this->assertFalse($interval->from_confirmed);
        $this->assertFalse($interval->to_confirmed);
    }

    public function test_schedule_change_inside_the_window_creates_two_versions_with_a_confirmed_boundary(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);

        // Alter Fahrplan bis 21.08., ab 24.08. fährt dieselbe Linie zehn Minuten später.
        $this->werktagsService('ALT', '2026-08-17', '2026-08-21');
        $this->fahrt('T-ALT', 'ALT', $line->route_id, ['07:00:00', '07:20:00']);
        $this->werktagsService('NEU', '2026-08-24', '2026-08-28');
        $this->fahrt('T-NEU', 'NEU', $line->route_id, ['07:10:00', '07:30:00']);

        $result = $this->konsolidieren();
        $this->assertSame(2, $result['versions_created']);
        $this->assertSame(1, $result['lines_changed']);

        $versionen = LineVersion::query()->where('line', '1')->with('intervals')->get()
            ->sortBy(fn (LineVersion $v) => $v->intervals->first()->valid_from)->values();

        // Der Wechsel wurde im Fenster beobachtet → innere Grenzen sind gesichert,
        // die äußeren bleiben offen (der Fahrplan lief davor und läuft danach weiter).
        $erste = $versionen[0]->intervals->first();
        $this->assertFalse($erste->from_confirmed);
        $this->assertTrue($erste->to_confirmed);

        $zweite = $versionen[1]->intervals->first();
        $this->assertTrue($zweite->from_confirmed);
        $this->assertFalse($zweite->to_confirmed);
    }

    public function test_return_to_previous_schedule_reuses_the_version_instead_of_creating_a_third(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);

        // Normal - Baustelle - wieder normal, alles innerhalb eines Fensters.
        $this->werktagsService('NORMAL-1', '2026-08-17', '2026-08-21');
        $this->fahrt('T-N1', 'NORMAL-1', $line->route_id, ['07:00:00', '07:20:00']);
        $this->werktagsService('BAUSTELLE', '2026-08-24', '2026-08-28');
        $this->fahrt('T-B', 'BAUSTELLE', $line->route_id, ['07:15:00', '07:45:00']);
        $this->werktagsService('NORMAL-2', '2026-08-31', '2026-09-04');
        $this->fahrt('T-N2', 'NORMAL-2', $line->route_id, ['07:00:00', '07:20:00']);

        $result = $this->konsolidieren();

        // Zwei Versionen, nicht drei — die Rückkehr hängt an der bestehenden Version.
        $this->assertSame(2, $result['versions_created']);
        $this->assertSame(2, LineVersion::query()->where('line', '1')->count());

        $normal = LineVersion::query()->where('line', '1')->where('version_no', 1)->sole();
        $this->assertCount(2, $normal->intervals, 'Normalfahrplan muss zwei Gültigkeits-Intervalle haben');
    }

    public function test_day_type_without_coverage_creates_no_version(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        $this->werktagsService('S1', '2026-08-17', '2026-08-21');
        $this->fahrt('T1', 'S1', $line->route_id, ['07:00:00', '07:20:00']);

        $this->konsolidieren();

        // Das Fenster (Mo-Fr) enthält weder Samstag noch Sonntag — dafür darf nichts entstehen.
        $typen = LineVersion::query()->pluck('day_type')->map->value->unique()->values()->all();
        $this->assertSame(['mo_fr'], $typen);
    }

    public function test_first_run_creates_a_bootstrap_period(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        $this->werktagsService('S1', '2026-08-17', '2026-08-21');
        $this->fahrt('T1', 'S1', $line->route_id, ['07:00:00', '07:20:00']);

        $this->konsolidieren();

        $periode = SchedulePeriod::query()->sole();
        $this->assertSame('bootstrap', $periode->created_via->value);
        $this->assertSame('current', $periode->status->value);
        $this->assertSame('2026-08-17', $periode->valid_from->toDateString());
    }

    public function test_calendar_dates_exception_is_reflected_in_the_fingerprint(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        $this->werktagsService('S1', '2026-08-17', '2026-08-28');
        $this->fahrt('T1', 'S1', $line->route_id, ['07:00:00', '07:20:00']);

        // Zweite Linie faehrt durchgehend. Erst dadurch ist der Ausfall der ersten Linie
        // ueberhaupt beobachtbar — ein Tag ganz ohne Betrieb waere eine Abdeckungsluecke.
        $andere = Route::factory()->create(['route_id' => 'R2', 'route_short_name' => '2']);
        $this->werktagsService('S2', '2026-08-17', '2026-08-28');
        $this->fahrt('T2', 'S2', $andere->route_id, ['08:00:00', '08:20:00']);

        // An einem einzelnen Werktag faellt der Betrieb der Linie 1 aus (Sperrung).
        CalendarDate::factory()->create(['service_id' => 'S1', 'date' => '2026-08-20', 'exception_type' => 2]);

        $this->konsolidieren();

        // Nur eine Version — der Fahrplan selbst ist unveraendert. Aber die Gueltigkeit
        // darf den Ausfalltag nicht ueberspannen, also zwei getrennte Intervalle.
        $version = LineVersion::query()->where('line', '1')->sole();
        $intervalle = $version->intervals()->orderBy('valid_from')->get();

        $this->assertCount(2, $intervalle, 'Der Ausfalltag muss die Gueltigkeit unterbrechen');
        $this->assertSame('2026-08-19', $intervalle[0]->valid_to->toDateString());
        $this->assertSame('2026-08-21', $intervalle[1]->valid_from->toDateString());

        // Der Wechsel wurde im Fenster beobachtet → die inneren Grenzen sind gesichert.
        $this->assertTrue($intervalle[0]->to_confirmed);
        $this->assertTrue($intervalle[1]->from_confirmed);
    }
}
