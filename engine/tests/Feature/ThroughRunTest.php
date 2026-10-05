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
use App\Models\TripLink;
use App\Models\User;
use App\Services\CourseLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Durchläufe am Tauschpunkt (KURSE §2 K10, ROADMAP I-16): das Merkmal am Anschluss, sein
 * Nachtrag und die Weitergabe einer Sichtung über Durchläufe — nicht über Wenden.
 */
final class ThroughRunTest extends TestCase
{
    use RefreshDatabase;

    private ConsolidatedFixtures $f;

    private LineVersion $eins;

    private LineVersion $fuenf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new ConsolidatedFixtures;
        $this->eins = $this->f->version('1');
        $this->fuenf = $this->f->version('5');
        $this->f->gueltigkeit($this->eins);
        $this->f->gueltigkeit($this->fuenf);
    }

    private function token(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    /** Linie 1 endet am City Carré, die 5 fährt vom selben Halt weiter. */
    private function paarAmTauschpunkt(): array
    {
        $an = $this->f->fahrt($this->eins, ['Hbf', 'City Carré'], ['07:00:00', '07:10:00']);
        $ab = $this->f->fahrt($this->fuenf, ['City Carré', 'Messe'], ['07:12:00', '07:30:00']);

        return [$an, $ab];
    }

    private function anschluss(ConsolidatedTrip $von, ConsolidatedTrip $nach, bool $durchlauf): TripLink
    {
        $fabrik = TripLink::factory();

        return ($durchlauf ? $fabrik->throughRun() : $fabrik)->create([
            'from_trip_id' => $von->id,
            'to_trip_id' => $nach->id,
            'stop_id' => $von->last_stop_id,
        ]);
    }

    private function kurs(string $nummer, ConsolidatedTrip ...$fahrten): Course
    {
        $kurs = Course::factory()->create([
            'period_id' => $this->eins->period_id,
            'day_type' => FahrplanTyp::MoFrNormal,
            'number' => $nummer,
        ]);

        foreach ($fahrten as $fahrt) {
            CourseTrip::factory()->create(['course_id' => $kurs->id, 'consolidated_trip_id' => $fahrt->id]);
        }

        return $kurs;
    }

    private function sichtung(ConsolidatedTrip $fahrt, string $nummer, SightingStatus $status = SightingStatus::Accepted): void
    {
        Sighting::factory()->create([
            'course_number' => $nummer,
            'consolidated_trip_id' => $fahrt->id,
            'match' => SightingMatch::Matched,
            'status' => $status,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function marken(ConsolidatedTrip ...$fahrten): array
    {
        $lookup = app(CourseLookup::class);

        return $lookup->sightingMarks($lookup->forTrips(array_map(static fn ($f): int => $f->id, $fahrten)));
    }

    // ---------------------------------------------------------------- Anlegen und Umschalten

    public function test_store_creates_a_through_run(): void
    {
        [$an, $ab] = $this->paarAmTauschpunkt();

        $this->withToken($this->token())
            ->postJson('/api/v1/admin/trip-links', [
                'kind' => 'link',
                'from_trip_id' => $an->id,
                'to_trip_id' => $ab->id,
                'through_run' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.through_run', true);

        $this->assertTrue(TripLink::query()->sole()->through_run);
    }

    public function test_store_defaults_to_a_turnaround(): void
    {
        [$an, $ab] = $this->paarAmTauschpunkt();

        $this->withToken($this->token())
            ->postJson('/api/v1/admin/trip-links', ['kind' => 'link', 'from_trip_id' => $an->id, 'to_trip_id' => $ab->id])
            ->assertCreated()
            ->assertJsonPath('data.through_run', false);
    }

    public function test_store_rejects_a_through_run_on_a_depot_decision(): void
    {
        [$an] = $this->paarAmTauschpunkt();

        $this->withToken($this->token())
            ->postJson('/api/v1/admin/trip-links', ['kind' => 'end', 'from_trip_id' => $an->id, 'through_run' => true])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);

        $this->assertSame(0, TripLink::query()->count());
    }

    public function test_through_run_toggle_switches_both_ways(): void
    {
        [$an, $ab] = $this->paarAmTauschpunkt();
        $link = $this->anschluss($an, $ab, false);

        $this->withToken($this->token())
            ->putJson("/api/v1/admin/trip-links/{$link->id}/through-run", ['through_run' => true])
            ->assertOk()
            ->assertJsonPath('data.through_run', true);
        $this->assertTrue($link->refresh()->through_run);

        $this->withToken($this->token())
            ->putJson("/api/v1/admin/trip-links/{$link->id}/through-run", ['through_run' => false])
            ->assertOk()
            ->assertJsonPath('data.through_run', false);
        $this->assertFalse($link->refresh()->through_run);
    }

    public function test_through_run_toggle_requires_authentication(): void
    {
        [$an, $ab] = $this->paarAmTauschpunkt();
        $link = $this->anschluss($an, $ab, false);

        $this->putJson("/api/v1/admin/trip-links/{$link->id}/through-run", ['through_run' => true])
            ->assertStatus(401);
    }

    public function test_through_run_toggle_rejects_depot_decisions(): void
    {
        [$an] = $this->paarAmTauschpunkt();
        $link = TripLink::factory()->end()->create(['from_trip_id' => $an->id, 'stop_id' => $an->last_stop_id]);

        $this->withToken($this->token())
            ->putJson("/api/v1/admin/trip-links/{$link->id}/through-run", ['through_run' => true])
            ->assertStatus(422);

        $this->assertFalse($link->refresh()->through_run);
    }

    public function test_board_and_timetable_expose_the_flag(): void
    {
        [$an, $ab] = $this->paarAmTauschpunkt();
        $this->anschluss($an, $ab, true);

        $board = $this->withToken($this->token())
            ->getJson('/api/v1/admin/stop-links?'.http_build_query([
                'stop_group' => $this->f->haltestelle('City Carré')->id,
                'period' => $this->eins->period_id,
                'day_type' => 'mo_fr',
            ]))
            ->assertOk()
            ->json('data');

        $this->assertTrue(collect($board['ending'])->firstWhere('id', $an->id)['decision']['through_run']);
        $this->assertTrue(collect($board['starting'])->firstWhere('id', $ab->id)['decision']['through_run']);

        $fahrplan = $this->withToken($this->token())
            ->getJson("/api/v1/admin/line-versions/{$this->fuenf->id}/timetable")
            ->assertOk()
            ->json('data.directions.0.trips.0.links.before.0');

        $this->assertTrue($fahrplan['through_run']);
    }

    // ---------------------------------------------------------------- Sichtung über Durchläufe

    public function test_sighting_marks_trips_across_through_runs(): void
    {
        [$an, $ab] = $this->paarAmTauschpunkt();
        $weiter = $this->f->fahrt($this->eins, ['Messe', 'Herrenkrug'], ['07:31:00', '07:40:00']);
        $this->anschluss($an, $ab, true);
        $this->anschluss($ab, $weiter, true);
        $this->kurs('03', $an, $ab, $weiter);

        $this->sichtung($an, '3');

        $marken = $this->marken($an, $ab, $weiter);

        $this->assertSame('seen', $marken[$an->id]);
        $this->assertSame('seen_through', $marken[$ab->id]);
        // Zwei Durchläufe weit — die Gruppe reicht so weit wie die Kette ohne Wende.
        $this->assertSame('seen_through', $marken[$weiter->id]);
    }

    public function test_sighting_works_backwards_along_a_through_run(): void
    {
        [$an, $ab] = $this->paarAmTauschpunkt();
        $this->anschluss($an, $ab, true);
        $this->kurs('03', $an, $ab);

        $this->sichtung($ab, '03', SightingStatus::Confirmed);

        $this->assertSame('seen_through', $this->marken($an)[$an->id] ?? null);
    }

    public function test_sighting_stops_at_a_turnaround(): void
    {
        [$an, $ab] = $this->paarAmTauschpunkt();
        $rueck = $this->f->fahrt($this->fuenf, ['Messe', 'City Carré'], ['07:40:00', '07:58:00']);
        $this->anschluss($an, $ab, true);
        $this->anschluss($ab, $rueck, false);
        $this->kurs('03', $an, $ab, $rueck);

        $this->sichtung($an, '03');

        $marken = $this->marken($ab, $rueck);

        $this->assertSame('seen_through', $marken[$ab->id]);
        $this->assertArrayNotHasKey($rueck->id, $marken);
    }

    public function test_sighting_with_another_number_does_not_propagate(): void
    {
        [$an, $ab] = $this->paarAmTauschpunkt();
        $this->anschluss($an, $ab, true);
        $this->kurs('03', $an, $ab);

        $this->sichtung($an, '04');

        $this->assertSame([], $this->marken($an, $ab));
    }

    public function test_own_dispute_wins_over_a_through_run(): void
    {
        [$an, $ab] = $this->paarAmTauschpunkt();
        $this->anschluss($an, $ab, true);
        $this->kurs('03', $an, $ab);

        $this->sichtung($an, '03');
        $this->sichtung($ab, '07', SightingStatus::Pending);

        $this->assertSame('disputed', $this->marken($ab)[$ab->id]);
    }

    public function test_pending_and_rejected_sightings_do_not_propagate(): void
    {
        [$an, $ab] = $this->paarAmTauschpunkt();
        $this->anschluss($an, $ab, true);
        $this->kurs('03', $an, $ab);

        $this->sichtung($an, '03', SightingStatus::Pending);
        $this->sichtung($an, '03', SightingStatus::Rejected);

        $this->assertSame([], $this->marken($ab));
    }

    // ---------------------------------------------------------------- Nachtrag für den Bestand

    public function test_mark_command_previews_without_writing(): void
    {
        [$an, $ab] = $this->paarAmTauschpunkt();
        $link = $this->anschluss($an, $ab, false);

        $this->artisan('trip-links:mark-through-runs', ['stop_group' => 'City Carré'])
            ->expectsOutputToContain('wären Durchläufe')
            ->assertSuccessful();

        $this->assertFalse($link->refresh()->through_run);
    }

    public function test_mark_command_applies_and_is_idempotent(): void
    {
        [$an, $ab] = $this->paarAmTauschpunkt();
        $link = $this->anschluss($an, $ab, false);
        $gruppe = $this->f->haltestelle('City Carré');

        $this->artisan('trip-links:mark-through-runs', ['stop_group' => (string) $gruppe->id, '--apply' => true])
            ->expectsOutputToContain('1 Anschlüsse')
            ->assertSuccessful();

        $this->assertTrue($link->refresh()->through_run);

        $this->artisan('trip-links:mark-through-runs', ['stop_group' => (string) $gruppe->id, '--apply' => true])
            ->expectsOutputToContain('nichts nachzutragen')
            ->assertSuccessful();
    }

    /** Wechselt das Fahrzeug den Bahnsteig, ist es eine Wende — der Nachtrag lässt sie stehen. */
    public function test_mark_command_skips_links_across_platforms(): void
    {
        $gruppe = $this->f->haltestelle('City Carré');
        $this->f->zurHaltestelle($this->f->halt('City Carré Süd'), $gruppe);
        $an = $this->f->fahrt($this->eins, ['Hbf', 'City Carré'], ['07:00:00', '07:10:00']);
        $ab = $this->f->fahrt($this->eins, ['City Carré Süd', 'Hbf'], ['07:15:00', '07:25:00']);
        $link = $this->anschluss($an, $ab, false);

        $this->artisan('trip-links:mark-through-runs', ['stop_group' => (string) $gruppe->id, '--apply' => true])
            ->expectsOutputToContain('nichts nachzutragen')
            ->assertSuccessful();

        $this->assertFalse($link->refresh()->through_run);
    }

    public function test_mark_command_reports_unknown_stop_group(): void
    {
        $this->artisan('trip-links:mark-through-runs', ['stop_group' => 'Gibtsnicht'])
            ->assertFailed();
    }
}
