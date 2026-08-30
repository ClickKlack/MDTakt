<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ConsolidatedStop;
use App\Models\ConsolidatedStopVersion;
use App\Services\ConsolidatedStopNameResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConsolidatedStopNameResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_attribute_version_wins(): void
    {
        $halt = ConsolidatedStop::factory()->create();
        ConsolidatedStopVersion::factory()->create([
            'consolidated_stop_id' => $halt->id,
            'name' => 'Magdeburg, Zoo',
            'valid_from' => '2026-08-15',
            'valid_to' => '2026-08-21',
        ]);
        ConsolidatedStopVersion::factory()->create([
            'consolidated_stop_id' => $halt->id,
            'name' => 'Zoo',
            'valid_from' => '2026-08-22',
            'valid_to' => '2026-09-28',
        ]);

        $namen = app(ConsolidatedStopNameResolver::class)->namesFor([$halt->id]);

        $this->assertSame('Zoo', $namen[$halt->id]);
    }

    public function test_only_requested_stops_are_resolved(): void
    {
        $gefragt = ConsolidatedStop::factory()->create();
        ConsolidatedStopVersion::factory()->create(['consolidated_stop_id' => $gefragt->id, 'name' => 'Alpha']);

        $ungefragt = ConsolidatedStop::factory()->create();
        ConsolidatedStopVersion::factory()->create(['consolidated_stop_id' => $ungefragt->id, 'name' => 'Beta']);

        $namen = app(ConsolidatedStopNameResolver::class)->namesFor([$gefragt->id]);

        $this->assertSame([$gefragt->id => 'Alpha'], $namen);
    }

    public function test_empty_and_null_input_is_harmless(): void
    {
        $this->assertSame([], app(ConsolidatedStopNameResolver::class)->namesFor([]));
        // Fahrten ohne aufgeloesten Start-/Zielhalt liefern null-Ids — die duerfen nicht stoeren.
        $this->assertSame([], app(ConsolidatedStopNameResolver::class)->namesFor([0]));
    }

    public function test_names_for_all_returns_every_stop(): void
    {
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $halt = ConsolidatedStop::factory()->create();
            ConsolidatedStopVersion::factory()->create(['consolidated_stop_id' => $halt->id, 'name' => $name]);
        }

        $this->assertCount(3, app(ConsolidatedStopNameResolver::class)->namesForAll());
    }
}
