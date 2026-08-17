<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\CalendarDate;
use App\Models\Route;
use App\Models\SchoolHoliday;
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

    /**
     * Baut den realen Fall nach: dieselbe Fahrt zur selben Uhrzeit einmal im
     * Werktags- und einmal im Sonntagsfahrplan (verschiedene service_id).
     */
    private function seedWerktagUndSonntag(): void
    {
        $line = Route::factory()->create(['route_id' => 'R6', 'route_short_name' => '6']);
        Stop::factory()->create(['stop_id' => 'H', 'stop_name' => 'Herrenkrug']);
        Stop::factory()->create(['stop_id' => 'D', 'stop_name' => 'Diesdorf']);

        Calendar::factory()->create([
            'service_id' => 'WERKTAG',
            'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true,
            'friday' => true, 'saturday' => false, 'sunday' => false,
            'start_date' => '2026-08-17', 'end_date' => '2026-09-04',
        ]);
        Calendar::factory()->create([
            'service_id' => 'SONNTAG',
            'monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false,
            'friday' => false, 'saturday' => false, 'sunday' => true,
            'start_date' => '2026-08-16', 'end_date' => '2026-09-06',
        ]);

        foreach ([['T-WT', 'WERKTAG'], ['T-SO', 'SONNTAG']] as [$tripId, $serviceId]) {
            Trip::factory()->create([
                'trip_id' => $tripId,
                'route_id' => $line->route_id,
                'service_id' => $serviceId,
            ]);
            $this->stopTime($tripId, 'H', 1, '07:26:00');
            $this->stopTime($tripId, 'D', 2, '08:01:00');
        }
    }

    public function test_trips_expose_day_pattern_so_identical_times_are_distinguishable(): void
    {
        $this->seedWerktagUndSonntag();

        $response = $this->getJson('/api/v1/lines/6/trips');

        // Ungefiltert stehen beide Fahrten nebeneinander — unterscheidbar am Muster.
        $response->assertOk()
            ->assertJsonPath('data.trip_count', 2)
            ->assertJsonPath('data.day_type', null)
            ->assertJsonPath('data.reference_date', null)
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonPath('data.groups.0.trip_count', 2);

        $muster = array_column($response->json('data.groups.0.trips'), 'day_pattern');
        sort($muster);
        $this->assertSame(['Mo-Fr', 'So'], $muster);
    }

    public function test_day_type_filter_returns_only_trips_of_that_schedule_type(): void
    {
        $this->seedWerktagUndSonntag();

        // So + Feiertage → Stichtag ist der erste Sonntag im Feed-Fenster (16.08.2026).
        $response = $this->getJson('/api/v1/lines/6/trips?day_type=so_feiertag');

        $response->assertOk()
            ->assertJsonPath('data.day_type', 'so_feiertag')
            ->assertJsonPath('data.day_type_label', 'So + Feiertage')
            ->assertJsonPath('data.reference_date', '2026-08-16')
            ->assertJsonPath('data.trip_count', 1)
            ->assertJsonPath('data.groups.0.trips.0.trip_id', 'T-SO')
            ->assertJsonPath('data.groups.0.trips.0.day_pattern', 'So');

        // Der Werktagsfahrplan liefert spiegelbildlich nur die andere Fahrt.
        $this->getJson('/api/v1/lines/6/trips?day_type=mo_fr')
            ->assertOk()
            ->assertJsonPath('data.reference_date', '2026-08-17')
            ->assertJsonPath('data.trip_count', 1)
            ->assertJsonPath('data.groups.0.trips.0.trip_id', 'T-WT');
    }

    public function test_day_type_without_coverage_in_feed_returns_empty_result(): void
    {
        $this->seedWerktagUndSonntag();

        // Ohne gepflegte Schulferien ist kein Tag des Feeds ein Ferien-Werktag.
        $this->getJson('/api/v1/lines/6/trips?day_type=mo_fr_ferien')
            ->assertOk()
            ->assertJsonPath('data.day_type', 'mo_fr_ferien')
            ->assertJsonPath('data.reference_date', null)
            ->assertJsonPath('data.trip_count', 0)
            ->assertJsonCount(0, 'data.groups');
    }

    public function test_school_holidays_shift_weekday_trips_into_the_ferien_type(): void
    {
        $this->seedWerktagUndSonntag();

        // Deckt das gesamte Feed-Fenster ab → jeder Werktag ist jetzt ein Ferientag.
        SchoolHoliday::factory()->create([
            'name' => 'Sommerferien 2026',
            'start_date' => '2026-07-13',
            'end_date' => '2026-09-30',
        ]);

        $this->getJson('/api/v1/lines/6/trips?day_type=mo_fr_ferien')
            ->assertOk()
            ->assertJsonPath('data.reference_date', '2026-08-17')
            ->assertJsonPath('data.trip_count', 1)
            ->assertJsonPath('data.groups.0.trips.0.trip_id', 'T-WT');

        // Derselbe Tag kann nicht zugleich normaler Werktag sein.
        $this->getJson('/api/v1/lines/6/trips?day_type=mo_fr')
            ->assertOk()
            ->assertJsonPath('data.reference_date', null)
            ->assertJsonPath('data.trip_count', 0);
    }

    /**
     * gtfs.de liefert Services, zu denen gar keine calendar-Zeile existiert — sie
     * verkehren ausschließlich an Einzelterminen aus calendar_dates. Ohne deren
     * Ausweisung stünde die Fahrt ohne jede Verkehrstag-Angabe da.
     */
    public function test_service_without_week_pattern_exposes_its_single_dates(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        Stop::factory()->create(['stop_id' => 'K', 'stop_name' => 'Kannenstieg']);
        Stop::factory()->create(['stop_id' => 'L', 'stop_name' => 'Listemannstraße']);

        // Kein Calendar::factory() — der Service existiert nur über calendar_dates.
        CalendarDate::factory()->create([
            'service_id' => 'NUR-EINZELTAG',
            'date' => '2026-08-16',
            'exception_type' => 1,
        ]);

        Trip::factory()->create([
            'trip_id' => 'T-EINZEL',
            'route_id' => $line->route_id,
            'service_id' => 'NUR-EINZELTAG',
        ]);
        $this->stopTime('T-EINZEL', 'K', 1, '20:55:00');
        $this->stopTime('T-EINZEL', 'L', 2, '21:12:00');

        $this->getJson('/api/v1/lines/1/trips')
            ->assertOk()
            ->assertJsonPath('data.groups.0.trips.0.day_pattern', null)
            ->assertJsonPath('data.groups.0.trips.0.service_dates', ['2026-08-16']);

        // Und er wird korrekt dem Fahrplantyp des Einzeltermins zugeordnet (16.08. = Sonntag).
        $this->getJson('/api/v1/lines/1/trips?day_type=so_feiertag')
            ->assertOk()
            ->assertJsonPath('data.trip_count', 1)
            ->assertJsonPath('data.groups.0.trips.0.trip_id', 'T-EINZEL');
    }

    /**
     * Eine Linienbezeichnung kann auf mehrere GTFS-Routen zeigen: N2 fährt im Feed
     * 08/2026 als Tram und — während des Schienenersatzverkehrs — als Bus. Beide
     * Routen landen in derselben Liste, das Verkehrsmittel unterscheidet sie.
     */
    public function test_line_served_by_two_modes_marks_the_mode_per_trip(): void
    {
        $tram = Route::factory()->create(['route_id' => 'R-TRAM', 'route_short_name' => 'N2', 'route_type' => 0]);
        $bus = Route::factory()->create(['route_id' => 'R-BUS', 'route_short_name' => 'N2', 'route_type' => 3]);
        Stop::factory()->create(['stop_id' => 'AC', 'stop_name' => 'Allee-Center']);
        Stop::factory()->create(['stop_id' => 'WH', 'stop_name' => 'Westerhüsen']);

        foreach ([['T-TRAM', $tram], ['T-BUS', $bus]] as [$tripId, $route]) {
            Trip::factory()->create(['trip_id' => $tripId, 'route_id' => $route->route_id]);
            $this->stopTime($tripId, 'AC', 1, '00:15:00');
            $this->stopTime($tripId, 'WH', 2, '00:45:00');
        }

        $response = $this->getJson('/api/v1/lines/N2/trips');

        // Gleiche Uhrzeit, gleiche Strecke — unterscheidbar allein am Verkehrsmittel.
        $response->assertOk()
            ->assertJsonPath('data.trip_count', 2)
            ->assertJsonPath('data.modes', ['bus', 'tram']);

        $modi = array_column($response->json('data.groups.0.trips'), 'mode', 'trip_id');
        $this->assertSame(['T-BUS' => 'bus', 'T-TRAM' => 'tram'], $modi);
    }

    public function test_single_mode_line_reports_exactly_one_mode(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1', 'route_type' => 0]);
        Stop::factory()->create(['stop_id' => 'A', 'stop_name' => 'Alpha']);
        Stop::factory()->create(['stop_id' => 'B', 'stop_name' => 'Beta']);

        Trip::factory()->create(['trip_id' => 'T1', 'route_id' => $line->route_id]);
        $this->stopTime('T1', 'A', 1, '06:00:00');
        $this->stopTime('T1', 'B', 2, '06:10:00');

        // Nur ein Verkehrsmittel → das Frontend blendet die Spalte aus.
        $this->getJson('/api/v1/lines/1/trips')
            ->assertOk()
            ->assertJsonPath('data.modes', ['tram'])
            ->assertJsonPath('data.groups.0.trips.0.mode', 'tram');
    }

    /**
     * Der erste Tag eines Typs ist oft der untypischste, weil Umbrüche am Anfang des
     * Feed-Fensters liegen. Realfall: am 16.08.2026 entfiel der reguläre Sonntagsdienst
     * und ein Sonderdienst fuhr stattdessen. Der Stichtag muss deshalb der häufigsten
     * Service-Zusammensetzung folgen, nicht dem ersten Treffer.
     */
    public function test_reference_date_follows_the_most_common_composition_not_the_first_day(): void
    {
        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        Stop::factory()->create(['stop_id' => 'A', 'stop_name' => 'Alpha']);
        Stop::factory()->create(['stop_id' => 'B', 'stop_name' => 'Beta']);

        // Regulärer Sonntagsdienst über das ganze Fenster — entfällt aber am ersten Sonntag.
        Calendar::factory()->create([
            'service_id' => 'REGULAER',
            'monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false,
            'friday' => false, 'saturday' => false, 'sunday' => true,
            'start_date' => '2026-08-16', 'end_date' => '2026-09-06',
        ]);
        CalendarDate::factory()->create([
            'service_id' => 'REGULAER',
            'date' => '2026-08-16',
            'exception_type' => 2,
        ]);

        // Sonderdienst, der genau an diesem einen Sonntag einspringt.
        CalendarDate::factory()->create([
            'service_id' => 'SONDER',
            'date' => '2026-08-16',
            'exception_type' => 1,
        ]);

        foreach ([['T-REG', 'REGULAER'], ['T-SOND', 'SONDER']] as [$tripId, $serviceId]) {
            Trip::factory()->create([
                'trip_id' => $tripId,
                'route_id' => $line->route_id,
                'service_id' => $serviceId,
            ]);
            $this->stopTime($tripId, 'A', 1, '20:55:00');
            $this->stopTime($tripId, 'B', 2, '21:12:00');
        }

        // Vier Sonntage im Fenster: 16.08. (Sonderfahrplan) und 23./30.08. + 06.09. (regulär).
        // Die Mehrheit gewinnt — Stichtag ist der erste Tag der häufigsten Variante.
        $this->getJson('/api/v1/lines/1/trips?day_type=so_feiertag')
            ->assertOk()
            ->assertJsonPath('data.reference_date', '2026-08-23')
            ->assertJsonPath('data.trip_count', 1)
            ->assertJsonPath('data.groups.0.trips.0.trip_id', 'T-REG');
    }

    public function test_unknown_day_type_is_rejected_with_422_envelope(): void
    {
        $this->getJson('/api/v1/lines/6/trips?day_type=montags')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }
}
