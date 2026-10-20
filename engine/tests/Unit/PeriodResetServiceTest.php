<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Calendar;
use App\Models\LineVersion;
use App\Models\LineVersionInterval;
use App\Services\PeriodResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wann eine neue Periodengrenze die Versionen zurücksetzen darf (FAHRPLANPERIODEN §4.1): nur,
 * wenn der aktuelle Lauf alles wieder aufbauen kann, was dabei fällt.
 */
final class PeriodResetServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PeriodResetService
    {
        return app(PeriodResetService::class);
    }

    /** Feed-Fenster 17.08.–28.08.2026. */
    private function fenster(): void
    {
        Calendar::factory()->create(['start_date' => '2026-08-17', 'end_date' => '2026-08-28']);
    }

    private function intervall(string $von, string $bis): LineVersionInterval
    {
        return LineVersionInterval::factory()->create([
            'line_version_id' => LineVersion::factory(),
            'valid_from' => $von,
            'valid_to' => $bis,
        ]);
    }

    public function test_can_reset_from_inside_the_feed_window(): void
    {
        $this->fenster();
        $this->intervall('2026-08-01', '2026-08-27');

        $this->assertTrue($this->service()->canResetFrom('2026-08-24'));
    }

    public function test_can_reset_from_the_first_day_of_the_window(): void
    {
        $this->fenster();
        $this->intervall('2026-08-01', '2026-08-27');

        // Grenzfall: Der erste Tag des Fensters wird neu beobachtet — nichts geht verloren.
        $this->assertTrue($this->service()->canResetFrom('2026-08-17'));
    }

    public function test_can_reset_from_refuses_a_day_before_the_window_with_observations_behind_it(): void
    {
        $this->fenster();
        $this->intervall('2026-08-01', '2026-08-27');

        // 10.–16.08. stehen nicht mehr im Lauf — das Zurücksetzen risse sie unwiederbringlich mit.
        $this->assertFalse($this->service()->canResetFrom('2026-08-10'));
    }

    public function test_can_reset_from_allows_a_day_before_the_window_when_nothing_is_observed_there(): void
    {
        $this->fenster();
        $this->intervall('2026-08-17', '2026-08-27');

        $this->assertTrue($this->service()->canResetFrom('2026-08-10'));
    }

    public function test_can_reset_from_without_any_import_only_when_nothing_would_fall(): void
    {
        $this->assertTrue($this->service()->canResetFrom('2026-08-10'));

        $this->intervall('2026-08-01', '2026-08-27');

        $this->assertFalse($this->service()->canResetFrom('2026-08-10'));
    }

    public function test_rollback_from_cuts_spanning_intervals_and_drops_later_ones(): void
    {
        $spannend = $this->intervall('2026-08-17', '2026-08-27');
        $danach = $this->intervall('2026-08-25', '2026-08-27');

        $this->service()->rollbackFrom('2026-08-24');

        $this->assertSame('2026-08-23', $spannend->refresh()->valid_to->toDateString());
        $this->assertTrue($spannend->to_confirmed);

        // Die Version existierte allein ab der Grenze — sie verschwindet mit ihrem Intervall.
        $this->assertDatabaseMissing('line_version_intervals', ['id' => $danach->id]);
        $this->assertDatabaseMissing('line_versions', ['id' => $danach->line_version_id]);
    }
}
