<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PeriodOrigin;
use App\Enums\PeriodStatus;
use App\Models\ConsolidatedStop;
use App\Models\ConsolidatedTrip;
use App\Models\LineVersion;
use App\Models\LineVersionInterval;
use App\Models\SchedulePeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminLineVersionsTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    private function periodeMitVersionen(): SchedulePeriod
    {
        $periode = SchedulePeriod::query()->create([
            'label' => 'Ausgangsperiode',
            'valid_from' => '2026-08-15',
            'valid_to' => null,
            'status' => PeriodStatus::Current,
            'created_via' => PeriodOrigin::Bootstrap,
        ]);

        // Version 1 endet mit einer gesicherten Grenze, Version 2 laeuft offen weiter.
        $alt = LineVersion::query()->create([
            'period_id' => $periode->id, 'line' => '1', 'day_type' => 'so_feiertag',
            'version_no' => 1, 'fingerprint' => str_repeat('a', 64),
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        LineVersionInterval::query()->create([
            'line_version_id' => $alt->id, 'valid_from' => '2026-08-16', 'valid_to' => '2026-08-16',
            'from_confirmed' => false, 'to_confirmed' => true,
        ]);

        $neu = LineVersion::query()->create([
            'period_id' => $periode->id, 'line' => '1', 'day_type' => 'so_feiertag',
            'version_no' => 2, 'fingerprint' => str_repeat('b', 64),
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        LineVersionInterval::query()->create([
            'line_version_id' => $neu->id, 'valid_from' => '2026-08-23', 'valid_to' => '2026-09-06',
            'from_confirmed' => true, 'to_confirmed' => false,
        ]);

        return $periode;
    }

    public function test_trip_count_comes_from_the_consolidate_not_the_raw_feed(): void
    {
        $this->periodeMitVersionen();
        $alt = LineVersion::query()->where('version_no', 1)->sole();
        $neu = LineVersion::query()->where('version_no', 2)->sole();

        // Beide Versionen tragen konsolidierte Fahrten. Version 1 liegt im August und damit
        // laengst ausserhalb des aktuellen Feed-Fensters — im Roh-Bestand gibt es sie nicht
        // mehr. Genau dafuer existiert das Konsolidat.
        $halt = ConsolidatedStop::query()->create([
            'anchor_lat' => '52.1400000', 'anchor_lon' => '11.6300000',
            'name_key' => 'alpha', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        foreach ([[$alt, 3], [$neu, 5]] as [$version, $anzahl]) {
            for ($i = 0; $i < $anzahl; $i++) {
                ConsolidatedTrip::query()->create([
                    'line_version_id' => $version->id,
                    'signature' => str_pad((string) $version->id.$i, 64, '0'),
                    'route_type' => 0,
                    'first_stop_id' => $halt->id, 'last_stop_id' => $halt->id,
                ]);
            }
        }

        $daten = $this->withToken($this->token())
            ->getJson('/api/v1/admin/line-versions')
            ->assertOk()
            ->json('data.lines.0.versions');

        // Vorher stand hier null ("—"), weil am Roh-Bestand gezaehlt wurde.
        $this->assertSame(3, $daten[0]['trip_count'], 'Version ausserhalb des Feed-Fensters muss ihre Fahrten zeigen');
        $this->assertSame(5, $daten[1]['trip_count']);
    }

    public function test_a_version_without_consolidated_content_reports_no_count(): void
    {
        $this->periodeMitVersionen();

        $daten = $this->withToken($this->token())
            ->getJson('/api/v1/admin/line-versions')
            ->assertOk()
            ->json('data.lines.0.versions');

        // Kein Inhalt konsolidiert — dann ist "keine Angabe" die richtige Aussage,
        // dieselbe, die die Abdeckungs-Anzeige als versions_without_content fuehrt.
        $this->assertNull($daten[0]['trip_count']);
    }

    public function test_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/admin/line-versions')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_returns_versions_with_intervals_and_boundary_flags(): void
    {
        $this->periodeMitVersionen();

        $response = $this->withToken($this->token())->getJson('/api/v1/admin/line-versions');

        $response->assertOk()
            ->assertJsonPath('data.period.created_via', 'bootstrap')
            ->assertJsonPath('data.lines.0.line', '1')
            ->assertJsonCount(2, 'data.lines.0.versions')
            ->assertJsonPath('data.lines.0.versions.0.day_type_label', 'So + Feiertage')
            ->assertJsonPath('data.lines.0.versions.0.intervals.0.to_confirmed', true)
            ->assertJsonPath('data.lines.0.versions.1.intervals.0.from_confirmed', true)
            ->assertJsonPath('data.lines.0.versions.1.intervals.0.to_confirmed', false);

        // Abdeckung spannt ueber alle Intervalle und zaehlt die Grenzen.
        $response->assertJsonPath('data.coverage.from', '2026-08-16')
            ->assertJsonPath('data.coverage.to', '2026-09-06')
            ->assertJsonPath('data.coverage.confirmed_boundaries', 2)
            ->assertJsonPath('data.coverage.open_boundaries', 2);
    }

    public function test_filters_by_line_and_day_type(): void
    {
        $this->periodeMitVersionen();
        $token = $this->token();

        $this->withToken($token)->getJson('/api/v1/admin/line-versions?line=99')
            ->assertOk()->assertJsonCount(0, 'data.lines');

        $this->withToken($token)->getJson('/api/v1/admin/line-versions?day_type=mo_fr')
            ->assertOk()->assertJsonCount(0, 'data.lines');

        $this->withToken($token)->getJson('/api/v1/admin/line-versions?day_type=so_feiertag')
            ->assertOk()->assertJsonCount(1, 'data.lines');
    }

    public function test_rejects_unknown_day_type(): void
    {
        $this->withToken($this->token())->getJson('/api/v1/admin/line-versions?day_type=montags')
            ->assertStatus(422)->assertJsonPath('error.code', 422);
    }

    public function test_without_period_the_response_is_empty_but_valid(): void
    {
        $this->withToken($this->token())->getJson('/api/v1/admin/line-versions')
            ->assertOk()
            ->assertJsonPath('data.period', null)
            ->assertJsonPath('data.coverage', null)
            ->assertJsonCount(0, 'data.lines');
    }
}
