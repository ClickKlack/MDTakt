<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ConsolidatedStopTime;
use App\Models\ConsolidatedTrip;
use App\Services\ConsolidatedTripTimeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Erste Abfahrt und letzte Ankunft je Fahrt — die Ableitung, auf der Fahrplan-Ansicht,
 * Haltestellen-Editor und Kursübersicht gemeinsam aufsetzen.
 */
final class ConsolidatedTripTimeResolverTest extends TestCase
{
    use RefreshDatabase;

    private ConsolidatedFixtures $f;

    private ConsolidatedTripTimeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new ConsolidatedFixtures;
        $this->resolver = app(ConsolidatedTripTimeResolver::class);
    }

    public function test_endpoints_come_from_first_and_last_sequence(): void
    {
        $version = $this->f->version();
        $fahrt = $this->f->fahrt($version, ['A', 'B', 'C'], ['06:00:00', '06:15:00', '06:30:00']);

        $zeiten = $this->resolver->endpoints([$fahrt->id]);

        $this->assertSame('06:00:00', $zeiten[$fahrt->id]['departure']);
        $this->assertSame('06:30:00', $zeiten[$fahrt->id]['arrival']);
    }

    /**
     * Zeiten jenseits von 24 Uhr gehören zum Betriebstag des Vortags und bleiben stehen —
     * sie dürfen weder umgerechnet noch auf 24 Stunden zurückgefaltet werden.
     */
    public function test_times_beyond_midnight_are_kept(): void
    {
        $version = $this->f->version('N1');
        $fahrt = $this->f->fahrt($version, ['A', 'B'], ['24:20:00', '25:10:00']);

        $zeiten = $this->resolver->endpoints([$fahrt->id]);

        $this->assertSame('24:20:00', $zeiten[$fahrt->id]['departure']);
        $this->assertSame('25:10:00', $zeiten[$fahrt->id]['arrival']);
    }

    /**
     * Die Grenzen kommen aus min/max der Sequenz, nicht aus der Einfügereihenfolge —
     * sonst hinge das Ergebnis daran, in welcher Folge die Haltzeiten geschrieben wurden.
     */
    public function test_sequence_not_insertion_order_decides(): void
    {
        $version = $this->f->version();
        $fahrt = $this->f->fahrt($version, ['A'], ['12:00:00']);

        // Nachträglich eine frühere und eine spätere Sequenz anhängen.
        ConsolidatedStopTime::factory()->create([
            'consolidated_trip_id' => $fahrt->id,
            'stop_id' => $this->f->halt('Z')->id,
            'stop_sequence' => 9,
            'arrival_time' => '12:40:00',
            'departure_time' => '12:40:00',
        ]);
        ConsolidatedStopTime::factory()->create([
            'consolidated_trip_id' => $fahrt->id,
            'stop_id' => $this->f->halt('Y')->id,
            'stop_sequence' => 0,
            'arrival_time' => '11:50:00',
            'departure_time' => '11:50:00',
        ]);

        $zeiten = $this->resolver->endpoints([$fahrt->id]);

        $this->assertSame('11:50:00', $zeiten[$fahrt->id]['departure']);
        $this->assertSame('12:40:00', $zeiten[$fahrt->id]['arrival']);
    }

    public function test_empty_input_yields_empty_result(): void
    {
        $this->assertSame([], $this->resolver->endpoints([]));
    }

    public function test_trip_without_stop_times_is_absent(): void
    {
        $version = $this->f->version();
        $fahrt = ConsolidatedTrip::factory()->create(['line_version_id' => $version->id]);

        $this->assertArrayNotHasKey($fahrt->id, $this->resolver->endpoints([$fahrt->id]));
    }
}
