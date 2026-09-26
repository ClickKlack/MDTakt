<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Enums\SightingMatch;
use App\Enums\SightingStatus;
use App\Models\ConsolidatedTrip;
use App\Models\Course;
use App\Models\CourseTrip;
use App\Models\LineVersion;
use App\Models\Sighting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Kursauskunft für MDKursTracker (Fluss 2): Linie + HAFAS-Halt + Soll-Zeit → Kurs.
 *
 * Im September gilt MESZ (UTC+2): `04:10Z` ist 06:10 Netz-Zeit.
 */
final class CourseLookupTest extends TestCase
{
    use RefreshDatabase;

    private const TRACKER_TOKEN = 'test-tracker-token';

    private const COLLECTOR_TOKEN = 'test-collector-token';

    private ConsolidatedFixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new ConsolidatedFixtures;
        config([
            'services.mdkurstracker.token' => self::TRACKER_TOKEN,
            'services.collector.token' => self::COLLECTOR_TOKEN,
        ]);
    }

    private function version(
        FahrplanTyp $typ = FahrplanTyp::MoFrNormal,
        string $von = '2026-08-17',
        string $bis = '2026-09-18',
    ): LineVersion {
        $version = $this->f->version('1', $typ);
        $this->f->gueltigkeit($version, $von, $bis);

        return $version;
    }

    private function kurs(ConsolidatedTrip $fahrt, string $nummer): void
    {
        $fahrt->loadMissing('lineVersion');
        $kurs = Course::factory()->create([
            'period_id' => $fahrt->lineVersion->period_id,
            'day_type' => $fahrt->lineVersion->day_type,
            'number' => $nummer,
        ]);
        CourseTrip::factory()->create(['course_id' => $kurs->id, 'consolidated_trip_id' => $fahrt->id]);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function frage(array $query, ?string $token = self::TRACKER_TOKEN): TestResponse
    {
        $anfrage = $token === null ? $this : $this->withToken($token);

        return $anfrage->getJson('/api/v1/collector/course-lookup?'.http_build_query($query));
    }

    /**
     * Zwei Fahrten fahren um 06:10 ab — an verschiedenen Bahnsteigen derselben Haltestelle.
     *
     * @return array{0: ConsolidatedTrip, 1: ConsolidatedTrip}
     */
    private function zweiFahrtenZurSelbenMinute(): array
    {
        $version = $this->version();
        $hin = $this->f->fahrt($version, ['A', 'Hasselbachplatz', 'C'], ['06:00:00', '06:10:00', '06:20:00']);
        $rueck = $this->f->fahrt($version, ['C', 'Hasselbachplatz Gegenseite', 'A'], ['06:00:00', '06:10:00', '06:20:00']);

        return [$hin, $rueck];
    }

    public function test_lookup_requires_the_tracker_token(): void
    {
        $query = ['hafas_stop' => '1', 'line' => '1', 'time' => '2026-09-01T04:10:00Z'];

        $this->frage($query, null)->assertStatus(401)->assertJsonPath('error.code', 401);
        $this->frage($query, self::COLLECTOR_TOKEN)->assertStatus(401);
    }

    public function test_lookup_happy_path_by_stop_name(): void
    {
        [$hin] = $this->zweiFahrtenZurSelbenMinute();
        $this->kurs($hin, '03');

        $this->frage(['hafas_stop' => '900002', 'line' => '1', 'time' => '2026-09-01T04:10:00Z', 'stop_name' => 'Magdeburg, Hasselbachplatz'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, private')
            ->assertJsonPath('data.found', true)
            ->assertJsonPath('data.course_number', '03')
            ->assertJsonPath('data.display', '1/03')
            ->assertJsonMissingPath('data.confidence')
            ->assertJsonPath('data.matched_trip.id', $hin->id)
            ->assertJsonPath('data.matched_trip.departure_local', '06:10:00')
            ->assertJsonPath('data.stop_resolved_via', 'name');
    }

    /**
     * Die HAFAS-ID lernt die Engine aus den Sichtungen: Der Halt der zugeordneten Fahrt zur Soll-Zeit
     * der Sichtung. Danach braucht es keinen Namen mehr.
     */
    public function test_lookup_by_hafas_stop_learned_from_sightings(): void
    {
        [$hin] = $this->zweiFahrtenZurSelbenMinute();
        $this->kurs($hin, '03');
        Sighting::factory()->create([
            'hafas_stop_id' => '900002',
            'course_number' => '3',
            'consolidated_trip_id' => $hin->id,
            'match' => SightingMatch::Matched,
            'status' => SightingStatus::Accepted,
            'departure_planned' => '2026-08-20T04:10:00Z',
        ]);

        $this->frage(['hafas_stop' => '900002', 'line' => '1', 'time' => '2026-09-01T04:10:00Z'])
            ->assertOk()
            ->assertJsonPath('data.matched_trip.id', $hin->id)
            ->assertJsonPath('data.stop_resolved_via', 'sighting');
    }

    public function test_lookup_without_stop_hint_is_ambiguous(): void
    {
        $this->zweiFahrtenZurSelbenMinute();

        $this->frage(['hafas_stop' => '900002', 'line' => '1', 'time' => '2026-09-01T04:10:00Z'])
            ->assertOk()
            ->assertExactJson(['data' => ['found' => false, 'reason' => 'ambiguous']]);
    }

    public function test_lookup_no_trip(): void
    {
        $this->zweiFahrtenZurSelbenMinute();

        // Eine Minute daneben: keine Toleranz, wie beim Eingang.
        $this->frage(['hafas_stop' => '900002', 'line' => '1', 'time' => '2026-09-01T04:11:00Z'])
            ->assertOk()
            ->assertJsonPath('data.reason', 'no-trip-match');
    }

    public function test_lookup_trip_without_course(): void
    {
        $this->zweiFahrtenZurSelbenMinute();

        $this->frage(['hafas_stop' => '900002', 'line' => '1', 'time' => '2026-09-01T04:10:00Z', 'stop_name' => 'Hasselbachplatz'])
            ->assertOk()
            ->assertJsonPath('data.reason', 'no-course-assigned');
    }

    /**
     * Mitternacht 1: Die Fahrt begann vor Mitternacht und steht mit 24:05 im Feed. Ihre Version
     * gilt nur bis zum 01.09. — gefragt wird am 02.09. um 00:05.
     */
    public function test_lookup_after_midnight_for_a_trip_that_started_before(): void
    {
        $fahrt = $this->f->fahrt($this->version(bis: '2026-09-01'), ['A', 'B'], ['23:50:00', '24:05:00']);
        $this->kurs($fahrt, '07');

        $this->frage(['hafas_stop' => 'x', 'line' => '1', 'time' => '2026-09-01T22:05:00Z'])
            ->assertOk()
            ->assertJsonPath('data.course_number', '07')
            ->assertJsonPath('data.matched_trip.departure_local', '24:05:00');
    }

    /**
     * Mitternacht 2: Die Fahrt beginnt nach Mitternacht (00:30) und gehört zum Vortag — Montag 00:40
     * ist Sonntagnacht. Die Mo–Fr-Fahrt mit denselben Zeiten darf nicht treffen.
     */
    public function test_lookup_after_midnight_belongs_to_the_previous_operating_day(): void
    {
        $this->f->fahrt($this->version(), ['A', 'B'], ['00:30:00', '00:40:00']);
        $sonntag = $this->f->fahrt($this->version(FahrplanTyp::SoFeiertag), ['A', 'B'], ['00:30:00', '00:40:00']);
        $this->kurs($sonntag, '11');

        $this->frage(['hafas_stop' => 'x', 'line' => '1', 'time' => '2026-09-06T22:40:00Z'])
            ->assertOk()
            ->assertJsonPath('data.course_number', '11')
            ->assertJsonPath('data.matched_trip.id', $sonntag->id);
    }

    /** Im Winter (MEZ, UTC+1) ist 06:10 lokal 05:10Z. */
    public function test_lookup_in_winter_time(): void
    {
        $fahrt = $this->f->fahrt($this->version(von: '2026-10-26', bis: '2026-11-30'), ['A', 'B'], ['06:00:00', '06:10:00']);
        $this->kurs($fahrt, '05');

        $this->frage(['hafas_stop' => 'x', 'line' => '1', 'time' => '2026-11-02T05:10:00Z'])
            ->assertOk()
            ->assertJsonPath('data.course_number', '05');
    }

    public function test_lookup_batch_echoes_refs(): void
    {
        [$hin] = $this->zweiFahrtenZurSelbenMinute();
        $this->kurs($hin, '03');

        $this->withToken(self::TRACKER_TOKEN)->postJson('/api/v1/collector/course-lookup', [
            'departures' => [
                ['ref' => 'a', 'hafas_stop' => '900002', 'line' => '1', 'time' => '2026-09-01T04:10:00Z', 'stop_name' => 'Hasselbachplatz'],
                ['ref' => 'b', 'hafas_stop' => '900002', 'line' => '9', 'time' => '2026-09-01T04:10:00Z'],
            ],
        ])
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, private')
            ->assertJsonPath('data.0.ref', 'a')
            ->assertJsonPath('data.0.course_number', '03')
            ->assertJsonPath('data.1.ref', 'b')
            ->assertJsonPath('data.1.reason', 'no-trip-match');
    }

    public function test_lookup_batch_limit(): void
    {
        $abfahrt = ['hafas_stop' => '1', 'line' => '1', 'time' => '2026-09-01T04:10:00Z'];

        $this->withToken(self::TRACKER_TOKEN)
            ->postJson('/api/v1/collector/course-lookup', ['departures' => array_fill(0, 101, $abfahrt)])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_lookup_rejects_non_utc_time(): void
    {
        $this->frage(['hafas_stop' => '1', 'line' => '1', 'time' => '2026-09-01T06:10:00+02:00'])->assertStatus(422);
    }

    /**
     * Beide Richtungen fahren zur selben Minute an gleichnamigen Bahnsteigen ab (Rostocker Straße
     * im Bestand). Das Ziel auf der Tafel trennt sie.
     */
    public function test_lookup_direction_separates_same_named_platforms(): void
    {
        $version = $this->version();
        $hin = $this->f->fahrt($version, ['A', 'Rostocker Straße', 'Rothensee'], ['06:00:00', '06:10:00', '06:20:00']);
        // Das Leerzeichen macht in den Fixtures einen zweiten Bahnsteig; normalisiert heißt er gleich.
        $rueck = $this->f->fahrt($version, ['Rothensee', 'Rostocker Straße ', 'A'], ['06:00:00', '06:10:00', '06:20:00']);
        $this->kurs($hin, '03');
        $this->kurs($rueck, '04');
        $query = ['hafas_stop' => 'x', 'line' => '1', 'time' => '2026-09-01T04:10:00Z', 'stop_name' => 'Rostocker Str.'];

        $this->frage($query)->assertJsonPath('data.reason', 'ambiguous');
        $this->frage($query + ['direction' => 'Magdeburg, Rothensee'])
            ->assertJsonPath('data.course_number', '03')
            ->assertJsonPath('data.stop_resolved_via', 'name+direction');
    }
}
