<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Enums\SightingMatch;
use App\Models\ConsolidatedTrip;
use App\Models\LineVersion;
use App\Models\MdktRoute;
use App\Services\SightingMatcher;
use App\Services\SightingMatchResult;
use App\Services\TripSignatureService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Zuordnung Sichtung → Fahrt über Linie + Fahrplantyp + Uhrzeitfolge (INTEGRATION_MDKURSTRACKER §4.1).
 *
 * Die Laufwege sind in UTC notiert wie beim Tracker. Im September gilt MESZ (UTC+2):
 * `04:00Z` ist 06:00 Netz-Zeit.
 */
final class SightingMatcherTest extends TestCase
{
    use RefreshDatabase;

    private ConsolidatedFixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new ConsolidatedFixtures;
    }

    private function matcher(): SightingMatcher
    {
        return app(SightingMatcher::class);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: ?string, 3?: ?string}>  $halte  [hafas, line, departure, arrival]
     */
    private function route(array $halte): MdktRoute
    {
        $stops = [];

        foreach ($halte as $i => $h) {
            $stops[] = [
                'seq' => $i + 1,
                'hafas_stop_id' => $h[0],
                'stop_name' => $h[0],
                'line' => $h[1],
                'departure_planned' => $h[2],
                'arrival_planned' => $h[3] ?? null,
            ];
        }

        return MdktRoute::factory()->create(['line' => $halte[0][1], 'stops' => $stops]);
    }

    private function version(
        string $line = '1',
        FahrplanTyp $typ = FahrplanTyp::MoFrNormal,
        string $von = '2026-08-17',
        string $bis = '2026-09-18',
        int $nr = 1,
    ): LineVersion {
        $version = $this->f->version($line, $typ, $nr);
        $this->f->gueltigkeit($version, $von, $bis);

        return $version;
    }

    /**
     * Eine Fahrt mit der Signatur, die der Import für diese Uhrzeitfolge schreiben würde.
     *
     * @param  array<int, string>  $uhrzeiten  „HH:MM"
     */
    private function fahrt(LineVersion $version, array $uhrzeiten): ConsolidatedTrip
    {
        $halte = array_map(static fn (int $i): string => "H{$version->line}-{$i}", array_keys($uhrzeiten));

        return $this->f->fahrt(
            $version,
            $halte,
            array_map(static fn (string $t): string => $t.':00', $uhrzeiten),
            TripSignatureService::signatureFor($version->line, $version->day_type->value, implode(',', $uhrzeiten)),
        );
    }

    private function standard(): MdktRoute
    {
        return $this->route([
            ['900001', '1', '2026-09-01T04:00:00Z'],
            ['900002', '1', '2026-09-01T04:10:00Z'],
            ['900003', '1', null, '2026-09-01T04:20:00Z'],
        ]);
    }

    private function match(MdktRoute $route, string $line, string $halt, string $abfahrt): SightingMatchResult
    {
        return $this->matcher()->match($route, $line, $halt, CarbonImmutable::parse($abfahrt));
    }

    public function test_match_happy_path(): void
    {
        $fahrt = $this->fahrt($this->version(), ['06:00', '06:10', '06:20']);

        $ergebnis = $this->match($this->standard(), '1', '900002', '2026-09-01T04:10:00Z');

        $this->assertSame(SightingMatch::Matched, $ergebnis->match);
        $this->assertSame($fahrt->id, $ergebnis->tripId);
        $this->assertSame('2026-09-01', $ergebnis->operatingDate);
    }

    public function test_match_no_trip(): void
    {
        $this->fahrt($this->version('2'), ['06:00', '06:10', '06:20']);

        $ergebnis = $this->match($this->standard(), '1', '900002', '2026-09-01T04:10:00Z');

        $this->assertNull($ergebnis->match);
        $this->assertSame('no-trip-match', $ergebnis->reason);
    }

    /**
     * Zeitfenster-Grenzfall: Es gibt keine Toleranz. Eine Minute Abweichung ist ein anderer Lauf.
     */
    public function test_match_one_minute_off_is_no_match(): void
    {
        $this->fahrt($this->version(), ['06:00', '06:10', '06:21']);

        $this->assertNull($this->match($this->standard(), '1', '900002', '2026-09-01T04:10:00Z')->match);
    }

    /**
     * Die Version muss am Betriebstag gelten — eine abgelaufene trifft nicht.
     */
    public function test_match_outside_the_version_interval_is_no_match(): void
    {
        $this->fahrt($this->version(von: '2026-08-17', bis: '2026-08-31'), ['06:00', '06:10', '06:20']);

        $this->assertNull($this->match($this->standard(), '1', '900002', '2026-09-01T04:10:00Z')->match);
    }

    /**
     * Mitternacht-Grenzfall 1: Eine Fahrt, die vor Mitternacht beginnt, läuft im Feed als 24:05
     * weiter und gehört zum Tag ihres Starts.
     */
    public function test_match_across_midnight_counts_past_24(): void
    {
        $route = $this->route([
            ['900001', '1', '2026-09-01T21:50:00Z'],   // 23:50 lokal
            ['900002', '1', '2026-09-01T22:05:00Z'],   // 00:05 lokal, Folgetag
        ]);
        // Die Version gilt nur bis zum 01.09. — der Treffer beweist, dass der Starttag zählt.
        $fahrt = $this->fahrt($this->version(von: '2026-08-17', bis: '2026-09-01'), ['23:50', '24:05']);

        $ergebnis = $this->match($route, '1', '900002', '2026-09-01T22:05:00Z');

        $this->assertSame(SightingMatch::Matched, $ergebnis->match);
        $this->assertSame($fahrt->id, $ergebnis->tripId);
        $this->assertSame('2026-09-01', $ergebnis->operatingDate);
    }

    /**
     * Mitternacht-Grenzfall 2: Eine Fahrt, die nach Mitternacht beginnt, steht im Feed mit 00:30,
     * gehört aber zum Betriebstag davor — und trägt dessen Fahrplantyp. Montag 00:40 ist Sonntagnacht.
     */
    public function test_match_after_midnight_belongs_to_the_previous_operating_day(): void
    {
        $route = $this->route([
            ['900001', '1', '2026-09-06T22:30:00Z'],   // Mo 07.09. 00:30 lokal
            ['900002', '1', '2026-09-06T22:40:00Z'],
        ]);
        $this->fahrt($this->version(), ['00:30', '00:40']);   // mo_fr — darf nicht treffen
        $sonntag = $this->fahrt($this->version(typ: FahrplanTyp::SoFeiertag), ['00:30', '00:40']);

        $ergebnis = $this->match($route, '1', '900002', '2026-09-06T22:40:00Z');

        $this->assertSame(SightingMatch::Matched, $ergebnis->match);
        $this->assertSame($sonntag->id, $ergebnis->tripId);
        $this->assertSame('2026-09-06', $ergebnis->operatingDate);
    }

    /**
     * Der Laufweg wurde im Sommer erfasst, gesichtet wird im Winter. Maßgeblich ist die Uhrzeit
     * vor Ort — jede Zeit wird mit ihrem eigenen Datum umgerechnet, nicht mit einem festen Versatz.
     */
    public function test_match_route_from_summer_matches_sighting_in_winter(): void
    {
        $fahrt = $this->fahrt(
            $this->version(von: '2026-10-26', bis: '2026-11-30'),
            ['06:00', '06:10', '06:20'],
        );

        // 02.11. (MEZ, UTC+1): 06:10 lokal ist 05:10Z.
        $ergebnis = $this->match($this->standard(), '1', '900002', '2026-11-02T05:10:00Z');

        $this->assertSame(SightingMatch::Matched, $ergebnis->match);
        $this->assertSame($fahrt->id, $ergebnis->tripId);
        $this->assertSame('2026-11-02', $ergebnis->operatingDate);
    }

    /**
     * Linienwechsel: Eine Tracker-Fahrt L5 → L1 ist im Feed zwei Fahrten. Der Übergangshalt C
     * gehört im Feed zu beiden, im Laufweg aber nur zu einer Linie.
     */
    public function test_match_line_change_splits_the_route(): void
    {
        $route = $this->route([
            ['A', '5', '2026-09-01T04:00:00Z'],
            ['B', '5', '2026-09-01T04:10:00Z'],
            ['C', '1', '2026-09-01T04:20:00Z'],
            ['D', '1', null, '2026-09-01T04:30:00Z'],
        ]);
        $fuenf = $this->fahrt($this->version('5'), ['06:00', '06:10', '06:20']);
        $eins = $this->fahrt($this->version('1'), ['06:20', '06:30']);

        $aufDerFuenf = $this->match($route, '5', 'A', '2026-09-01T04:00:00Z');
        $aufDerEins = $this->match($route, '1', 'D', '2026-09-01T04:30:00Z');

        $this->assertSame($fuenf->id, $aufDerFuenf->tripId);
        $this->assertSame($eins->id, $aufDerEins->tripId);
    }

    public function test_match_several_trips_is_ambiguous(): void
    {
        $version = $this->version();
        $this->fahrt($version, ['06:00', '06:10', '06:20']);
        $this->fahrt($version, ['06:00', '06:10', '06:20']);

        $ergebnis = $this->match($this->standard(), '1', '900002', '2026-09-01T04:10:00Z');

        $this->assertSame(SightingMatch::Ambiguous, $ergebnis->match);
        $this->assertNull($ergebnis->tripId);
    }

    /**
     * Baustellenfahrplan: Der Tracker kennt den Laufweg schon, der Feed erst in der Version ab
     * dem 19.09. Neun Tage später liegt innerhalb der Frist.
     */
    public function test_match_next_version_within_the_window(): void
    {
        $this->version();   // gilt am 10.09., kennt die Fahrt nicht
        $folge = $this->fahrt($this->version(von: '2026-09-19', bis: '2026-10-16', nr: 2), ['06:00', '06:10', '06:20']);

        $ergebnis = $this->match($this->standard(), '1', '900002', '2026-09-10T04:10:00Z');

        $this->assertSame(SightingMatch::MatchedNextVersion, $ergebnis->match);
        $this->assertSame($folge->id, $ergebnis->tripId);
    }

    public function test_match_next_version_beyond_the_window_is_no_match(): void
    {
        $this->fahrt($this->version(von: '2026-09-19', bis: '2026-10-16', nr: 2), ['06:00', '06:10', '06:20']);

        // 01.09. → 19.09. sind 18 Tage, mehr als die 14 der Frist.
        $this->assertNull($this->match($this->standard(), '1', '900002', '2026-09-01T04:10:00Z')->match);
    }

    public function test_match_stop_not_on_route(): void
    {
        $ergebnis = $this->match($this->standard(), '1', '999999', '2026-09-01T05:55:00Z');

        $this->assertNull($ergebnis->match);
        $this->assertSame('stop-not-on-route', $ergebnis->reason);
    }

    /**
     * Rückmeldung des Trackers: Alle Laufweg-Zeiten tragen das Datum des ersten HAFAS-Abrufs — auch
     * die nach Mitternacht. 00:10Z ist hier eigentlich schon der Folgetag, steht aber mit dem Datum des
     * Abrufs da. Der Tageswechsel muss aus der zurückspringenden Uhrzeit kommen, nicht aus dem Datum.
     */
    public function test_match_route_dates_are_ignored_across_midnight(): void
    {
        $route = $this->route([
            ['900001', '1', '2026-04-15T21:50:00Z'],   // 23:50 lokal
            ['900002', '1', '2026-04-15T00:10:00Z'],   // 02:10 lokal — Datum des Abrufs, nicht des Tages
        ]);
        $fahrt = $this->fahrt($this->version(von: '2026-08-17', bis: '2026-09-01'), ['23:50', '26:10']);

        // Gesichtet am 02.09. um 02:10 — die Fahrt gehört zum Betriebstag 01.09.
        $ergebnis = $this->match($route, '1', '900002', '2026-09-02T00:10:00Z');

        $this->assertSame(SightingMatch::Matched, $ergebnis->match);
        $this->assertSame($fahrt->id, $ergebnis->tripId);
        $this->assertSame('2026-09-01', $ergebnis->operatingDate);
    }

    /**
     * Rückmeldung des Trackers: Am Endhalt steht die Ankunft im Feld `departure_planned`.
     */
    public function test_match_end_stop_arrival_in_departure_field(): void
    {
        $route = $this->route([
            ['900001', '1', '2026-04-15T04:00:00Z'],
            ['900002', '1', '2026-04-15T04:10:00Z'],
            ['900003', '1', '2026-04-15T04:20:00Z'],   // Endhalt: Ankunft, im Abfahrtsfeld
        ]);
        $fahrt = $this->fahrt($this->version(), ['06:00', '06:10', '06:20']);

        $this->assertSame($fahrt->id, $this->match($route, '1', '900002', '2026-09-01T04:10:00Z')->tripId);
    }
}
