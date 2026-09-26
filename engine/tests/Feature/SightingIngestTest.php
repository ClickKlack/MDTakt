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
use App\Services\SightingIngestService;
use App\Services\TripSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Sichtungs-Eingang aus MDKursTracker: Absicherung, Idempotenz, Auto-Bestätigung, Neu-Zuordnen.
 */
final class SightingIngestTest extends TestCase
{
    use RefreshDatabase;

    private const TRACKER_TOKEN = 'test-tracker-token';

    private const COLLECTOR_TOKEN = 'test-collector-token';

    private const FINGERPRINT = 'fp-linie-1-0600';

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

    private function version(string $von = '2026-08-17', string $bis = '2026-09-18', int $nr = 1): LineVersion
    {
        $version = $this->f->version('1', FahrplanTyp::MoFrNormal, $nr);
        $this->f->gueltigkeit($version, $von, $bis);

        return $version;
    }

    private function fahrt(?LineVersion $version = null): ConsolidatedTrip
    {
        return $this->f->fahrt(
            $version ?? $this->version(),
            ['A', 'B', 'C'],
            ['06:00:00', '06:10:00', '06:20:00'],
            TripSignatureService::signatureFor('1', 'mo_fr', '06:00,06:10,06:20'),
        );
    }

    private function kurs(ConsolidatedTrip $fahrt, string $nummer): Course
    {
        $fahrt->loadMissing('lineVersion');
        $kurs = Course::factory()->create([
            'period_id' => $fahrt->lineVersion->period_id,
            'day_type' => FahrplanTyp::MoFrNormal,
            'number' => $nummer,
        ]);
        CourseTrip::factory()->create(['course_id' => $kurs->id, 'consolidated_trip_id' => $fahrt->id]);

        return $kurs;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(int $recordingId = 1935, string $kurs = '03', string $tag = '2026-09-01'): array
    {
        return [
            'sync' => ['since' => null, 'generated_at' => '2026-09-01T04:11:00Z'],
            'trips' => [[
                'mdkt_trip_id' => 776,
                'schedule_fingerprint' => self::FINGERPRINT,
                'line' => '1',
                'direction' => 'Sudenburg',
                'day_type' => 'MO-FR',
                'stops' => [
                    ['seq' => 1, 'hafas_stop_id' => '900001', 'stop_name' => 'A', 'line' => '1', 'departure_planned' => '2026-09-01T04:00:00Z'],
                    ['seq' => 2, 'hafas_stop_id' => '900002', 'stop_name' => 'B', 'line' => '1', 'departure_planned' => '2026-09-01T04:10:00Z'],
                    ['seq' => 3, 'hafas_stop_id' => '900003', 'stop_name' => 'C', 'line' => '1', 'arrival_planned' => '2026-09-01T04:20:00Z'],
                ],
            ]],
            'sightings' => [[
                'mdkt_recording_id' => $recordingId,
                'schedule_fingerprint' => self::FINGERPRINT,
                'hafas_stop_id' => '900002',
                'stop_name' => 'B',
                'line' => '1',
                'course_number' => $kurs,
                'service_date' => $tag,
                'observed_at' => $tag.'T04:09:30Z',
                'departure_planned' => $tag.'T04:10:00Z',
                'departure_actual' => null,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function sende(array $body, ?string $token = self::TRACKER_TOKEN): TestResponse
    {
        $anfrage = $token === null ? $this : $this->withToken($token);

        return $anfrage->postJson('/api/v1/collector/sightings', $body);
    }

    public function test_ingest_requires_the_tracker_token(): void
    {
        $this->sende($this->body(), null)->assertStatus(401)->assertJsonPath('error.code', 401);
        $this->sende($this->body(), 'falsch')->assertStatus(401);
    }

    /**
     * Getrennte Zugänge: Der Collector-Token öffnet den Sichtungs-Eingang nicht, der
     * Tracker-Token nicht den GTFS-Import.
     */
    public function test_ingest_tokens_are_not_interchangeable(): void
    {
        $this->sende($this->body(), self::COLLECTOR_TOKEN)->assertStatus(401);
        $this->withToken(self::TRACKER_TOKEN)->getJson('/api/v1/collector/imports')->assertStatus(401);
    }

    public function test_ingest_is_closed_without_configured_token(): void
    {
        config(['services.mdkurstracker.token' => null]);

        $this->sende($this->body())->assertStatus(401);
    }

    public function test_ingest_happy_path_matches_the_trip(): void
    {
        $fahrt = $this->fahrt();

        $this->sende($this->body())
            ->assertOk()
            ->assertJsonPath('data.received.sightings', 1)
            ->assertJsonPath('data.results.0.outcome', 'created')
            ->assertJsonPath('data.results.0.match', 'matched')
            ->assertJsonPath('data.results.0.status', 'pending')
            ->assertJsonPath('data.results.0.consolidated_trip_id', $fahrt->id)
            ->assertJsonPath('data.unmatched_fingerprints', [])
            ->assertJsonPath('data.watermark.max_recording_id', 1935)
            ->assertJsonPath('data.watermark.max_observed_at', '2026-09-01T04:09:30Z');

        $this->assertDatabaseCount('mdkt_routes', 1);
        $this->assertDatabaseHas('sightings', ['mdkt_recording_id' => 1935, 'consolidated_trip_id' => $fahrt->id]);
    }

    public function test_ingest_is_idempotent(): void
    {
        $this->fahrt();

        $this->sende($this->body())->assertOk();
        $this->sende($this->body())->assertOk()->assertJsonPath('data.results.0.outcome', 'unchanged');

        $this->assertDatabaseCount('sightings', 1);
        $this->assertDatabaseCount('mdkt_routes', 1);
    }

    /**
     * Beim Nachholen kennt die Engine den Laufweg schon — die Sichtung darf ihn weglassen.
     */
    public function test_ingest_accepts_a_known_fingerprint_without_route(): void
    {
        $this->fahrt();
        $this->sende($this->body())->assertOk();

        $ohneRoute = $this->body(1936);
        $ohneRoute['trips'] = [];

        $this->sende($ohneRoute)->assertOk()->assertJsonPath('data.results.0.match', 'matched');
    }

    public function test_ingest_unknown_fingerprint_is_reported(): void
    {
        $body = $this->body();
        $body['trips'] = [];

        $this->sende($body)->assertOk()->assertJsonPath('data.results.0.outcome', 'unknown_fingerprint');
        $this->assertDatabaseCount('sightings', 0);
    }

    public function test_ingest_confirms_when_the_course_already_matches(): void
    {
        $this->kurs($this->fahrt(), '03');

        // „3" statt „03": dieselbe Nummer.
        $this->sende($this->body(kurs: '3'))->assertOk()->assertJsonPath('data.results.0.status', 'confirmed');
    }

    public function test_ingest_leaves_a_differing_course_pending(): void
    {
        $this->kurs($this->fahrt(), '04');

        $this->sende($this->body())->assertOk()->assertJsonPath('data.results.0.status', 'pending');
    }

    /**
     * Ein Treffer erst in der Folgeversion wird nie von selbst bestätigt.
     */
    public function test_ingest_never_confirms_a_next_version_match(): void
    {
        $this->version();
        $folge = $this->fahrt($this->version('2026-09-19', '2026-10-16', 2));
        $this->kurs($folge, '03');

        $this->sende($this->body(tag: '2026-09-10'))
            ->assertOk()
            ->assertJsonPath('data.results.0.match', 'matched_next_version')
            ->assertJsonPath('data.results.0.status', 'pending');
    }

    public function test_ingest_without_trip_waits_for_the_timetable(): void
    {
        $this->sende($this->body())
            ->assertOk()
            ->assertJsonPath('data.results.0.match', 'waiting')
            ->assertJsonPath('data.unmatched_fingerprints', [self::FINGERPRINT]);
    }

    /**
     * Ändert der Tracker die Kursnummer einer entschiedenen Sichtung, ist das eine neue Aussage.
     */
    public function test_ingest_reopens_a_decided_sighting_when_it_changes(): void
    {
        $this->fahrt();
        $this->sende($this->body())->assertOk();
        Sighting::query()->update(['status' => SightingStatus::Rejected->value, 'decided_at' => now()]);

        $this->sende($this->body(kurs: '07'))->assertOk()->assertJsonPath('data.results.0.outcome', 'updated');

        $sichtung = Sighting::query()->sole();
        $this->assertSame(SightingStatus::Pending, $sichtung->status);
        $this->assertSame('07', $sichtung->course_number);
        $this->assertNull($sichtung->decided_at);
    }

    public function test_ingest_rejects_too_many_sightings(): void
    {
        $body = $this->body();
        $body['sightings'] = array_map(fn (int $i): array => $this->body($i)['sightings'][0], range(1, 501));

        $this->sende($body)->assertStatus(422)->assertJsonPath('error.code', 422);
    }

    public function test_ingest_rejects_non_utc_times(): void
    {
        $body = $this->body();
        $body['sightings'][0]['departure_planned'] = '2026-09-01T06:10:00+02:00';

        $this->sende($body)->assertStatus(422);
    }

    /**
     * Baustelle: Der Tracker kennt den Laufweg vor dem Feed. Nach dem Import ist die Fahrt da.
     */
    public function test_rematch_finds_the_trip_after_the_import(): void
    {
        $this->sende($this->body())->assertOk();
        $fahrt = $this->fahrt();

        $zahlen = app(SightingIngestService::class)->rematchOpen();

        $this->assertSame(1, $zahlen['matched']);
        $sichtung = Sighting::query()->sole();
        $this->assertSame(SightingMatch::Matched, $sichtung->match);
        $this->assertSame($fahrt->id, $sichtung->consolidated_trip_id);
        $this->assertSame(0, $sichtung->match_attempts);
    }

    public function test_rematch_turns_waiting_into_no_trip_after_two_imports(): void
    {
        $this->sende($this->body())->assertOk();
        $dienst = app(SightingIngestService::class);

        $dienst->rematchOpen();
        $this->assertSame(SightingMatch::Waiting, Sighting::query()->sole()->match);

        $dienst->rematchOpen();
        $this->assertSame(SightingMatch::NoTrip, Sighting::query()->sole()->match);
    }

    public function test_rematch_by_hand_does_not_count_as_import(): void
    {
        $this->sende($this->body())->assertOk();
        $dienst = app(SightingIngestService::class);

        $dienst->rematchOpen(countAttempt: false);
        $dienst->rematchOpen(countAttempt: false);

        $this->assertSame(SightingMatch::Waiting, Sighting::query()->sole()->match);
    }

    public function test_rematch_reconnects_a_trip_the_import_removed(): void
    {
        $alt = $this->fahrt();
        $this->sende($this->body())->assertOk();
        Sighting::query()->update(['status' => SightingStatus::Accepted->value]);

        $alt->delete();
        $neu = $this->fahrt();
        app(SightingIngestService::class)->rematchOpen();

        $sichtung = Sighting::query()->sole();
        $this->assertSame($neu->id, $sichtung->consolidated_trip_id);
        $this->assertSame(SightingStatus::Accepted, $sichtung->status);
    }

    public function test_ingest_file_dry_run_reports_and_stores_nothing(): void
    {
        $this->fahrt();
        $datei = tempnam(sys_get_temp_dir(), 'mdkt');
        file_put_contents($datei, json_encode($this->body()));

        $this->artisan('sightings:ingest-file', ['path' => $datei, '--dry-run' => true])
            ->expectsOutputToContain('1 Sichtungen, 1 mit Fahrt (100.0 %)')
            ->assertSuccessful();

        $this->assertDatabaseCount('sightings', 0);
        $this->assertDatabaseCount('mdkt_routes', 0);
        unlink($datei);
    }

    /**
     * Integrationstest mit dem Tracker: Er sendet an der Sichtung keinen Haltnamen. Der Laufweg hat ihn.
     */
    public function test_ingest_takes_the_stop_name_from_the_route(): void
    {
        $body = $this->body();
        unset($body['sightings'][0]['stop_name']);
        $body['trips'][0]['stops'][1]['stop_name'] = 'Magdeburg, Am Nordpark';

        $this->sende($body)->assertOk();

        $this->assertSame('Am Nordpark', Sighting::query()->sole()->stop_name);
    }
}
