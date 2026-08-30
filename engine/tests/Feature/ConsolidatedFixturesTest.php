<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Models\ConsolidatedStop;
use App\Models\ConsolidatedStopTime;
use App\Models\ConsolidatedTrip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Sichert das Test-Fundament selbst ab: Ohne verlässliche Factories stünde jeder Fahrplan-
 * und Diff-Test auf Sand.
 */
final class ConsolidatedFixturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_a_version_with_trips_and_stop_times(): void
    {
        $f = new ConsolidatedFixtures;
        $version = $f->version('1', FahrplanTyp::MoFrNormal);

        $f->fahrt($version, ['Kannenstieg', 'Milchweg', 'Listemannstr.'], ['07:00:00', '07:05:00', '07:12:00']);
        $f->fahrt($version, ['Kannenstieg', 'Milchweg', 'Listemannstr.'], ['07:20:00', '07:25:00', '07:32:00']);

        $this->assertSame(2, ConsolidatedTrip::query()->where('line_version_id', $version->id)->count());
        $this->assertSame(6, ConsolidatedStopTime::query()->count());
        $this->assertSame(3, ConsolidatedStop::query()->count(), 'Gleicher Name = gleiche Identitaet');
    }

    public function test_the_same_stop_can_appear_twice_in_one_trip(): void
    {
        $f = new ConsolidatedFixtures;
        $version = $f->version();

        // Wendeschleife: Wanzleber Chaussee wird zweimal beruehrt.
        $fahrt = $f->fahrt(
            $version,
            ['Bördepark', 'Wanzleber Chaussee', 'Sonnenanger', 'Wanzleber Chaussee', 'Am Teich'],
            ['08:00:00', '08:04:00', '08:07:00', '08:11:00', '08:15:00'],
        );

        $this->assertSame(4, ConsolidatedStop::query()->count(), 'Vier Identitaeten bei fuenf Halten');
        $this->assertSame(5, $fahrt->stopTimes()->count());

        $wanzleber = ConsolidatedStop::query()->where('name_key', 'wanzleber chaussee')->sole();
        $zeiten = ConsolidatedStopTime::query()
            ->where('consolidated_trip_id', $fahrt->id)
            ->where('stop_id', $wanzleber->id)
            ->orderBy('stop_sequence')
            ->pluck('departure_time')
            ->all();

        $this->assertSame(['08:04:00', '08:11:00'], $zeiten, 'Beide Beruehrungen mit eigener Zeit');
    }

    public function test_versions_share_one_period_by_default(): void
    {
        $f = new ConsolidatedFixtures;

        $a = $f->version('1', FahrplanTyp::MoFrNormal, 1);
        $b = $f->version('1', FahrplanTyp::MoFrNormal, 2);

        $this->assertSame($a->period_id, $b->period_id);
        $this->assertNotSame($a->fingerprint, $b->fingerprint);
    }
}
