<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\Route;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use App\Models\TripSignature;
use App\Services\OperatingDayResolver;
use App\Services\TripSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Betriebstag-Wechsel: Eine Fahrt vor der Grenze gehört zum Vortag.
 *
 * Verkehrsbetriebe drücken das sonst über Zeiten jenseits 24:00 aus („26:00"). Der
 * gtfs.de-Feed tut das nicht — keine einzige Fahrt im Realbestand beginnt jenseits 24:00 —,
 * also muss der Betriebstag rekonstruiert werden.
 */
final class OperatingDayTest extends TestCase
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

    /** Ein Service, der an genau einem Wochentag im angegebenen Zeitraum verkehrt. */
    private function serviceAn(string $serviceId, string $wochentag, string $von, string $bis): void
    {
        $muster = array_fill_keys(
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            false,
        );
        $muster[$wochentag] = true;

        Calendar::factory()->create($muster + [
            'service_id' => $serviceId,
            'start_date' => $von,
            'end_date' => $bis,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function typenVon(string $tripId): array
    {
        return TripSignature::query()->where('trip_id', $tripId)->pluck('day_type')
            ->map(static fn ($t): string => is_string($t) ? $t : $t->value)
            ->sort()->values()->all();
    }

    public function test_night_and_day_lines_get_different_boundaries(): void
    {
        $resolver = app(OperatingDayResolver::class);

        $this->assertSame(3 * 3600, $resolver->boundaryFor('1'));
        $this->assertSame(3 * 3600, $resolver->boundaryFor('73'));
        $this->assertSame(12 * 3600, $resolver->boundaryFor('N1'));

        $this->assertFalse($resolver->isNightLine('10'));
        $this->assertTrue($resolver->isNightLine('N9'));
    }

    /**
     * Der Kern des Problems (FAHRPLANPERIODEN §8): Die Nacht von Sonntag auf Montag ist eine
     * Sonntagnacht. GTFS führt diese Fahrten als Montagsfahrten — ohne Korrektur bekäme die
     * Nachtlinie montags einen anderen Fahrplan als Di–Fr.
     */
    public function test_a_night_trip_after_midnight_belongs_to_the_previous_operating_day(): void
    {
        $nacht = Route::factory()->create(['route_id' => 'RN', 'route_short_name' => 'N1']);

        // 2026-09-21 ist ein Montag.
        $this->serviceAn('MO', 'monday', '2026-09-21', '2026-09-21');
        $this->fahrt('T-NACHT', 'MO', $nacht->route_id, ['01:30:00', '02:00:00']);

        app(TripSignatureService::class)->rebuild();

        // Betriebstag ist der Sonntag davor.
        $this->assertSame(['so_feiertag'], $this->typenVon('T-NACHT'));
    }

    public function test_an_evening_night_trip_stays_on_its_calendar_day(): void
    {
        $nacht = Route::factory()->create(['route_id' => 'RN', 'route_short_name' => 'N1']);

        $this->serviceAn('MO', 'monday', '2026-09-21', '2026-09-21');
        $this->fahrt('T-ABEND', 'MO', $nacht->route_id, ['23:10:00', '23:40:00']);

        app(TripSignatureService::class)->rebuild();

        $this->assertSame(['mo_fr'], $this->typenVon('T-ABEND'));
    }

    /**
     * Die Grenze der Taglinien liegt bei 03:00 und damit vor deren frühester Fahrt (03:47 im
     * Realbestand) — eine Morgenfahrt darf sie nicht auf den Vortag ziehen.
     */
    public function test_an_early_morning_day_trip_stays_on_its_calendar_day(): void
    {
        $tag = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);

        $this->serviceAn('MO', 'monday', '2026-09-21', '2026-09-21');
        $this->fahrt('T-FRUEH', 'MO', $tag->route_id, ['03:47:00', '04:10:00']);

        app(TripSignatureService::class)->rebuild();

        $this->assertSame(['mo_fr'], $this->typenVon('T-FRUEH'));
    }

    /**
     * Für Taglinien ist die Grenze heute wirkungslos, aber nicht bedeutungslos: Bekäme eine
     * Taglinie je eine Fahrt nach Mitternacht, gehörte sie zum Vortag.
     */
    public function test_a_day_trip_before_the_boundary_belongs_to_the_previous_day(): void
    {
        $tag = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);

        $this->serviceAn('MO', 'monday', '2026-09-21', '2026-09-21');
        $this->fahrt('T-NACHTZUSATZ', 'MO', $tag->route_id, ['02:15:00', '02:40:00']);

        app(TripSignatureService::class)->rebuild();

        $this->assertSame(['so_feiertag'], $this->typenVon('T-NACHTZUSATZ'));
    }

    /**
     * Eine Nachtlinie, die jede Nacht der Woche verkehrt, darf im `mo_fr`-Strang **einen**
     * Fahrplan haben — nicht montags einen anderen als Di–Fr. Genau daran zerfiel die
     * Versionsbildung: An Herrenkrug ergaben sich 16 Fahrplanstände über vier Wochen.
     */
    public function test_a_nightly_line_has_one_weekday_strand_not_two(): void
    {
        $nacht = Route::factory()->create(['route_id' => 'RN', 'route_short_name' => 'N1']);

        // Die Nächte auf Di bis Sa gehören zu den Betriebstagen Mo bis Fr.
        foreach (['tuesday', 'wednesday', 'thursday', 'friday', 'saturday'] as $i => $wochentag) {
            $this->serviceAn("S{$i}", $wochentag, '2026-09-22', '2026-09-26');
            $this->fahrt("T-{$i}", "S{$i}", $nacht->route_id, ['01:30:00', '02:00:00']);
        }

        app(TripSignatureService::class)->rebuild();

        // Alle fünf gehören zu Mo-Fr — die Nacht auf Samstag ist die Freitagnacht.
        foreach (range(0, 4) as $i) {
            $this->assertSame(['mo_fr'], $this->typenVon("T-{$i}"), "Fahrt T-{$i} im falschen Strang");
        }
    }

    public function test_a_trip_without_readable_times_stays_on_its_calendar_day(): void
    {
        $resolver = app(OperatingDayResolver::class);

        // Keine Zeit heisst nicht "vor der Grenze" — lieber beim Kalendertag bleiben, als
        // auf eine Vermutung hin zu verschieben.
        $this->assertFalse($resolver->shiftsBack('N1', null));
        $this->assertTrue($resolver->shiftsBack('N1', 3600));
        $this->assertFalse($resolver->shiftsBack('1', 4 * 3600));
    }
}
