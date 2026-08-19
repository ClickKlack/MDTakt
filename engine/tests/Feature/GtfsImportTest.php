<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GtfsImportTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-collector-token';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.collector.token' => self::TOKEN]);
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(): array
    {
        return [
            'routes' => [
                ['route_id' => '1', 'route_short_name' => '1', 'route_type' => 0],
            ],
            'stops' => [
                ['stop_id' => 'S1', 'stop_name' => 'Hasselbachplatz', 'lat' => 52.1205, 'lon' => 11.6276],
                ['stop_id' => 'S2', 'stop_name' => 'Alter Markt', 'lat' => 52.1318, 'lon' => 11.6395],
            ],
            'trips' => [
                ['trip_id' => 'T1', 'route_id' => '1', 'service_id' => 'W1', 'block_id' => 'B1', 'direction_id' => 0],
            ],
            'calendar' => [
                ['service_id' => 'W1', 'monday' => 1, 'tuesday' => 1, 'wednesday' => 1, 'thursday' => 1, 'friday' => 1, 'saturday' => 0, 'sunday' => 0, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
            ],
            'calendar_dates' => [
                ['service_id' => 'W1', 'date' => '2026-06-22', 'exception_type' => 1],
            ],
            'feed_info' => [
                'feed_version' => '2026-06-20T03:00',
                'feed_start_date' => '2026-06-20',
                'feed_end_date' => '2026-12-13',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stopTimesPayload(): array
    {
        return [
            ['trip_id' => 'T1', 'stop_id' => 'S1', 'arrival_time' => '05:30:00', 'departure_time' => '05:30:00', 'stop_sequence' => 1],
            ['trip_id' => 'T1', 'stop_id' => 'S2', 'arrival_time' => '05:34:00', 'departure_time' => '05:34:00', 'stop_sequence' => 2],
        ];
    }

    /**
     * Führt den kompletten Lifecycle (plain JSON) durch und gibt die run_id zurück.
     */
    private function runFullImport(): int
    {
        $start = $this->withToken(self::TOKEN)->postJson('/api/v1/collector/imports', $this->basePayload());
        $start->assertCreated();
        $runId = (int) $start->json('data.run_id');

        $this->withToken(self::TOKEN)
            ->postJson("/api/v1/collector/imports/{$runId}/stop-times", ['stop_times' => $this->stopTimesPayload()])
            ->assertOk();

        $this->withToken(self::TOKEN)
            ->postJson("/api/v1/collector/imports/{$runId}/finish")
            ->assertOk();

        return $runId;
    }

    /**
     * Sendet einen gzip-komprimierten JSON-Body (wie der echte Collector).
     */
    private function postGzip(string $uri, array $data, ?string $token = self::TOKEN)
    {
        $server = [
            'HTTP_CONTENT_ENCODING' => 'gzip',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call('POST', $uri, [], [], [], $server, (string) gzencode((string) json_encode($data)));
    }

    public function test_start_rejects_missing_token(): void
    {
        $this->postJson('/api/v1/collector/imports', $this->basePayload())
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_full_lifecycle_stores_data_and_records_run(): void
    {
        $start = $this->withToken(self::TOKEN)->postJson('/api/v1/collector/imports', $this->basePayload());
        $start->assertCreated()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.imported.stop_times', 0)
            ->assertJsonPath('data.imported.calendar', 1);
        $runId = (int) $start->json('data.run_id');

        $this->withToken(self::TOKEN)
            ->postJson("/api/v1/collector/imports/{$runId}/stop-times", ['stop_times' => $this->stopTimesPayload()])
            ->assertOk()
            ->assertJsonPath('data.imported.stop_times', 2);

        $this->withToken(self::TOKEN)
            ->postJson("/api/v1/collector/imports/{$runId}/finish")
            ->assertOk()
            ->assertJsonPath('data.status', 'success');

        $this->assertDatabaseHas('routes', ['route_id' => '1', 'route_type' => 0]);
        $this->assertDatabaseHas('stop_times', ['trip_id' => 'T1', 'stop_sequence' => 2, 'departure_time' => '05:34:00']);
        $this->assertDatabaseCount('stops', 2);
        $this->assertDatabaseHas('calendar', ['service_id' => 'W1', 'monday' => true, 'saturday' => false]);
        $this->assertDatabaseHas('gtfs_import_runs', ['id' => $runId, 'status' => 'success', 'feed_version' => '2026-06-20T03:00']);
    }

    public function test_gzip_encoded_body_is_decompressed(): void
    {
        $start = $this->postGzip('/api/v1/collector/imports', $this->basePayload());

        $start->assertCreated()->assertJsonPath('data.imported.routes', 1);
        $this->assertDatabaseHas('trips', ['trip_id' => 'T1']);
    }

    public function test_invalid_gzip_body_returns_400(): void
    {
        $server = [
            'HTTP_CONTENT_ENCODING' => 'gzip',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ];
        $this->call('POST', '/api/v1/collector/imports', [], [], [], $server, 'not-actually-gzip')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 400);
    }

    public function test_start_validation_error_uses_error_envelope(): void
    {
        $payload = $this->basePayload();
        unset($payload['routes'][0]['route_id']);

        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/collector/imports', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422)
            ->assertJsonStructure(['error' => ['code', 'message']]);
    }

    public function test_finishing_an_already_finished_run_conflicts(): void
    {
        $runId = $this->runFullImport();

        $this->withToken(self::TOKEN)
            ->postJson("/api/v1/collector/imports/{$runId}/finish")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 409);
    }

    public function test_stop_times_with_unknown_trip_marks_run_failed(): void
    {
        $start = $this->withToken(self::TOKEN)->postJson('/api/v1/collector/imports', $this->basePayload());
        $runId = (int) $start->json('data.run_id');

        // Verweist auf einen nicht existierenden Trip → FK-Verletzung → Lauf schlägt fehl.
        $this->withToken(self::TOKEN)->postJson("/api/v1/collector/imports/{$runId}/stop-times", [
            'stop_times' => [
                ['trip_id' => 'GHOST', 'stop_id' => 'S1', 'arrival_time' => '05:30:00', 'departure_time' => '05:30:00', 'stop_sequence' => 1],
            ],
        ]);

        $this->assertDatabaseHas('gtfs_import_runs', ['id' => $runId, 'status' => 'failed']);
        $this->assertNotNull(DB::table('gtfs_import_runs')->where('id', $runId)->value('error_message'));
    }

    public function test_lifecycle_is_idempotent(): void
    {
        $this->runFullImport();
        $this->runFullImport();

        $this->assertDatabaseCount('stops', 2);
        $this->assertDatabaseCount('trips', 1);
        $this->assertDatabaseCount('stop_times', 2);
        $this->assertDatabaseCount('gtfs_import_runs', 2);
    }

    public function test_new_build_with_different_ids_replaces_previous(): void
    {
        // Build A (route '1', trip 'T1', Halte S1/S2)
        $this->runFullImport();

        // Build B mit komplett anderen IDs (gtfs.de-Neuvergabe je Build), gleiche Linie '1'
        $build = [
            'routes' => [['route_id' => '99', 'route_short_name' => '1', 'route_type' => 0]],
            'stops' => [
                ['stop_id' => 'S9', 'stop_name' => 'Neuer Halt A', 'lat' => 52.1, 'lon' => 11.6],
                ['stop_id' => 'S10', 'stop_name' => 'Neuer Halt B', 'lat' => 52.2, 'lon' => 11.7],
            ],
            'trips' => [['trip_id' => 'T9', 'route_id' => '99', 'service_id' => 'W9', 'block_id' => null, 'direction_id' => 0]],
            'calendar' => [
                ['service_id' => 'W9', 'monday' => 1, 'tuesday' => 1, 'wednesday' => 1, 'thursday' => 1, 'friday' => 1, 'saturday' => 0, 'sunday' => 0, 'start_date' => '2026-07-01', 'end_date' => '2026-12-31'],
            ],
            'calendar_dates' => [['service_id' => 'W9', 'date' => '2026-07-01', 'exception_type' => 1]],
            'feed_info' => ['feed_version' => '2026-07-01T03:00', 'feed_start_date' => '2026-07-01', 'feed_end_date' => '2026-12-31'],
        ];
        $stopTimes = [
            ['trip_id' => 'T9', 'stop_id' => 'S9', 'arrival_time' => '06:00:00', 'departure_time' => '06:00:00', 'stop_sequence' => 1],
            ['trip_id' => 'T9', 'stop_id' => 'S10', 'arrival_time' => '06:05:00', 'departure_time' => '06:05:00', 'stop_sequence' => 2],
        ];

        $runId = (int) $this->withToken(self::TOKEN)->postJson('/api/v1/collector/imports', $build)->json('data.run_id');
        $this->withToken(self::TOKEN)->postJson("/api/v1/collector/imports/{$runId}/stop-times", ['stop_times' => $stopTimes])->assertOk();
        $this->withToken(self::TOKEN)->postJson("/api/v1/collector/imports/{$runId}/finish")->assertOk();

        // Kein Akkumulieren: nur noch Build B im Bestand …
        $this->assertDatabaseCount('routes', 1);
        $this->assertDatabaseCount('trips', 1);
        $this->assertDatabaseCount('stops', 2);
        $this->assertDatabaseCount('stop_times', 2);
        $this->assertDatabaseHas('routes', ['route_id' => '99']);

        // … die alten Build-A-Zeilen sind weg …
        $this->assertDatabaseMissing('routes', ['route_id' => '1']);
        $this->assertDatabaseMissing('trips', ['trip_id' => 'T1']);
        $this->assertDatabaseMissing('stops', ['stop_id' => 'S1']);

        // … aber die Audit-Historie der Läufe bleibt erhalten.
        $this->assertDatabaseCount('gtfs_import_runs', 2);
    }

    public function test_collector_endpoints_throttle_excessive_requests(): void
    {
        // Das Limit (120/min) greift auch ohne gültigen Token — eine Flut kostet so keine
        // Token-Vergleiche und keine gzip-Dekompression.
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/collector/imports')->assertStatus(401);
        }

        $this->getJson('/api/v1/collector/imports')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 429)
            ->assertJsonPath('error.message', 'Too many requests.');
    }

    public function test_full_import_stays_below_the_rate_limit(): void
    {
        // Grenzfall-Absicherung: Ein realer Lauf (Start + stop_times-Chunks + Abschluss) darf
        // das Limit nie erreichen — ein verlorener Lauf bedeutet eine unwiederbringliche Woche.
        $this->runFullImport();

        $remaining = $this->withToken(self::TOKEN)
            ->getJson('/api/v1/collector/imports')
            ->assertOk()
            ->headers->get('X-RateLimit-Remaining');

        // Ein MVB-Feed sendet rund 19 Requests; hier sind es weniger, aber deutlich Luft muss bleiben.
        $this->assertGreaterThan(100, (int) $remaining);
    }

    public function test_imports_endpoint_requires_token(): void
    {
        $this->getJson('/api/v1/collector/imports')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_imports_endpoint_lists_runs_and_current_state(): void
    {
        $this->runFullImport();

        $response = $this->withToken(self::TOKEN)->getJson('/api/v1/collector/imports');

        $response->assertOk()
            ->assertJsonPath('data.current.tables.stop_times', 2)
            ->assertJsonPath('data.current.tables.calendar', 1)
            ->assertJsonPath('data.current.feed_version', '2026-06-20T03:00')
            ->assertJsonCount(1, 'data.runs')
            ->assertJsonPath('data.runs.0.status', 'success');

        $this->assertNotNull($response->json('data.current.last_success_at'));
    }
}
