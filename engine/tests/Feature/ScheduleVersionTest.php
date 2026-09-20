<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PeriodOrigin;
use App\Enums\PeriodStatus;
use App\Models\Calendar;
use App\Models\CalendarDate;
use App\Models\LineVersion;
use App\Models\Route;
use App\Models\SchedulePeriod;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use App\Models\TripSignature;
use App\Services\ConsolidatedScheduleService;
use App\Services\SchedulePeriodService;
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

    public function test_period_boundary_inside_the_window_splits_the_interval_and_restarts_numbering(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        $this->werktagsService('S1', '2026-08-17', '2026-08-28');
        $this->fahrt('T1', 'S1', $line->route_id, ['07:00:00', '07:20:00']);

        // Der Admin hat einen Fahrplanwechsel zum 24.08. deklariert — mitten im Feed-Fenster.
        $alt = SchedulePeriod::query()->create([
            'label' => 'Alte Periode', 'valid_from' => '2026-08-01', 'valid_to' => null,
            'status' => PeriodStatus::Frozen, 'created_via' => PeriodOrigin::Admin,
        ]);
        $neu = SchedulePeriod::query()->create([
            'label' => 'Neue Periode', 'valid_from' => '2026-08-24', 'valid_to' => null,
            'status' => PeriodStatus::Frozen, 'created_via' => PeriodOrigin::Admin,
        ]);
        app(SchedulePeriodService::class)->rebuildChain();

        $this->konsolidieren();

        // Der Fahrplan ist unveraendert, aber die Periodengrenze teilt ihn: je Periode eine
        // Version — und in der neuen faengt die Zaehlung wieder bei 1 an (§4.1).
        $vorher = LineVersion::query()->where('period_id', $alt->id)->where('line', '1')->sole();
        $nachher = LineVersion::query()->where('period_id', $neu->id)->where('line', '1')->sole();
        $this->assertSame(1, $vorher->version_no);
        $this->assertSame(1, $nachher->version_no);
        $this->assertSame($vorher->fingerprint, $nachher->fingerprint, 'Gleicher Fahrplan, gleicher Fingerprint');

        $davor = $vorher->intervals()->sole();
        $danach = $nachher->intervals()->sole();
        $this->assertSame('2026-08-21', $davor->valid_to->toDateString());
        $this->assertSame('2026-08-24', $danach->valid_from->toDateString());

        // Eine Periodengrenze ist ein exakt bekanntes Datum — anders als eine Fensterkante
        // ist sie keine blosse Untergrenze.
        $this->assertTrue($davor->to_confirmed);
        $this->assertTrue($danach->from_confirmed);

        // Die aeusseren Kanten liegen weiterhin am Fensterrand und bleiben offen.
        $this->assertFalse($davor->from_confirmed);
        $this->assertFalse($danach->to_confirmed);
    }

    public function test_a_confirmed_boundary_survives_a_later_window_edge(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);

        // Erster Import: Der Wechsel zum 24.08. wird INNERHALB des Fensters beobachtet —
        // Freitag 21.08. traegt den alten, Montag 24.08. den neuen Fahrplan.
        $this->werktagsService('ALT', '2026-08-17', '2026-08-21');
        $this->fahrt('T-ALT', 'ALT', $line->route_id, ['07:00:00', '07:20:00']);
        $this->werktagsService('NEU', '2026-08-24', '2026-08-28');
        $this->fahrt('T-NEU', 'NEU', $line->route_id, ['07:10:00', '07:30:00']);

        $this->konsolidieren();

        $neueVersion = LineVersion::query()->where('line', '1')->where('version_no', 2)->sole();
        $this->assertTrue(
            $neueVersion->intervals()->sole()->from_confirmed,
            'Der Wechsel wurde im Fenster beobachtet',
        );

        // Folge-Import: Das Fenster beginnt jetzt GENAU am Wechseltag. Aus dieser Sicht ist
        // der 24.08. bloss eine Fensterkante — der Lauf kann den Wechsel nicht sehen.
        StopTime::query()->delete();
        Trip::query()->delete();
        Calendar::query()->delete();
        $this->werktagsService('NEU2', '2026-08-24', '2026-09-04');
        $this->fahrt('T-NEU2', 'NEU2', $line->route_id, ['07:10:00', '07:30:00']);

        $this->konsolidieren();

        // Die frueher gemachte Beobachtung darf dadurch nicht verloren gehen. Sonst
        // vergaesse das System mit jedem Import, was es einmal wusste.
        $intervall = $neueVersion->refresh()->intervals()->orderBy('valid_from')->first();
        $this->assertSame('2026-08-24', $intervall->valid_from->toDateString());
        $this->assertTrue($intervall->from_confirmed, 'Eine gesicherte Grenze darf nicht zurueckfallen');
    }

    public function test_a_later_run_displaces_an_older_version_from_reobserved_days(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);

        // Erster Lauf: ein Fahrplan fuer zwei Wochen.
        $this->werktagsService('ALT', '2026-08-17', '2026-08-28');
        $this->fahrt('T-ALT', 'ALT', $line->route_id, ['07:00:00', '07:20:00']);
        $this->konsolidieren();

        $alteVersion = LineVersion::query()->where('line', '1')->sole();
        $this->assertSame('2026-08-28', $alteVersion->intervals()->sole()->valid_to->toDateString());

        // Zweiter Lauf: Der Feed revidiert die zweite Woche rueckwirkend.
        StopTime::query()->delete();
        Trip::query()->delete();
        Calendar::query()->delete();
        $this->werktagsService('NEU', '2026-08-24', '2026-09-04');
        $this->fahrt('T-NEU', 'NEU', $line->route_id, ['07:05:00', '07:25:00']);
        $this->konsolidieren();

        // Die alte Version darf die neu beobachteten Tage nicht mehr beanspruchen —
        // sonst lieferte eine Datumsabfrage fuer den 24.08. beide Fahrplaene.
        $alt = $alteVersion->refresh()->intervals()->sole();
        $this->assertSame('2026-08-17', $alt->valid_from->toDateString());
        $this->assertSame('2026-08-23', $alt->valid_to->toDateString(), 'Der alte Fahrplan endet, wo der neue beginnt');

        $neu = LineVersion::query()->where('line', '1')->where('version_no', 2)->sole();
        $this->assertSame('2026-08-24', $neu->intervals()->sole()->valid_from->toDateString());

        // Und die Gegenprobe: Kein Tag traegt zwei Fahrplaene.
        $this->assertSame(
            1,
            count(app(ConsolidatedScheduleService::class)->trips(['date' => '2026-08-24', 'line' => '1'])),
            'Ein Tag darf nur einen Fahrplan liefern',
        );
    }

    public function test_a_fully_superseded_version_keeps_its_trips_but_loses_validity(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);

        $this->werktagsService('ALT', '2026-08-24', '2026-08-28');
        $this->fahrt('T-ALT', 'ALT', $line->route_id, ['07:00:00', '07:20:00']);
        $this->konsolidieren();

        $alteVersion = LineVersion::query()->where('line', '1')->sole();
        $fahrtenVorher = $alteVersion->consolidatedTrips()->count();
        $this->assertGreaterThan(0, $fahrtenVorher);

        // Ein spaeterer Lauf ueberdeckt denselben Zeitraum vollstaendig mit anderem Fahrplan.
        StopTime::query()->delete();
        Trip::query()->delete();
        Calendar::query()->delete();
        $this->werktagsService('NEU', '2026-08-24', '2026-08-28');
        $this->fahrt('T-NEU', 'NEU', $line->route_id, ['08:00:00', '08:20:00']);
        $this->konsolidieren();

        // Entschieden am 20.09.2026: Die Version bleibt samt Fahrten bestehen, nur ohne
        // Gueltigkeit — sie haelt fest, was der Feed einmal behauptet hat.
        $alteVersion->refresh();
        $this->assertSame(0, $alteVersion->intervals()->count(), 'Keine Gueltigkeit mehr');
        $this->assertSame($fahrtenVorher, $alteVersion->consolidatedTrips()->count(), 'Fahrten bleiben erhalten');
        $this->assertNotNull(LineVersion::query()->find($alteVersion->id), 'Die Version selbst bleibt');
    }
}
