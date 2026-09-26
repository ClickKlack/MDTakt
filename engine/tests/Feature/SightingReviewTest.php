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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Prüfliste der Sichtungen: Filter, Live-Vergleich, Annehmen (ganze Kette), Ablehnen.
 */
final class SightingReviewTest extends TestCase
{
    use RefreshDatabase;

    private ConsolidatedFixtures $f;

    private LineVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new ConsolidatedFixtures;
        $this->version = $this->f->version('1', FahrplanTyp::MoFrNormal);
        $this->f->gueltigkeit($this->version);
    }

    private function token(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    private function fahrt(string $ab = '06:00:00', string $an = '06:30:00'): ConsolidatedTrip
    {
        return $this->f->fahrt($this->version, ['A', 'B'], [$ab, $an]);
    }

    private function kurs(ConsolidatedTrip $fahrt, string $nummer): Course
    {
        $kurs = Course::factory()->create([
            'period_id' => $this->version->period_id,
            'day_type' => FahrplanTyp::MoFrNormal,
            'number' => $nummer,
        ]);
        CourseTrip::factory()->create(['course_id' => $kurs->id, 'consolidated_trip_id' => $fahrt->id]);

        return $kurs;
    }

    /**
     * @param  array<string, mixed>  $werte
     */
    private function sichtung(?ConsolidatedTrip $fahrt, string $nummer = '03', array $werte = []): Sighting
    {
        return Sighting::factory()->create($werte + [
            'course_number' => $nummer,
            'consolidated_trip_id' => $fahrt?->id,
            'match' => $fahrt === null ? SightingMatch::Waiting : SightingMatch::Matched,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function liste(array $query = []): array
    {
        return $this->withToken($this->token())
            ->getJson('/api/v1/admin/sightings?'.http_build_query($query))
            ->assertOk()
            ->json('data');
    }

    public function test_review_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/sightings')->assertStatus(401)->assertJsonPath('error.code', 401);
        $this->postJson('/api/v1/admin/sightings/accept', ['ids' => [1]])->assertStatus(401);
    }

    /**
     * Die Warteschlange zeigt nur Offenes, das sich entscheiden lässt — „wartet auf Fahrplan"
     * und Entschiedenes erst über den Filter.
     */
    public function test_list_open_hides_waiting_and_decided(): void
    {
        $offen = $this->sichtung($this->fahrt());
        $wartend = $this->sichtung(null);
        $keineFahrt = $this->sichtung(null, werte: ['match' => SightingMatch::NoTrip]);
        $this->sichtung($this->fahrt('07:00:00', '07:30:00'), werte: ['status' => SightingStatus::Rejected]);

        $this->assertEqualsCanonicalizing([$offen->id, $keineFahrt->id], array_column($this->liste(), 'id'));
        $this->assertSame([$wartend->id], array_column($this->liste(['state' => 'waiting']), 'id'));
        $this->assertCount(1, $this->liste(['state' => 'rejected']));
        $this->assertCount(4, $this->liste(['state' => 'all']));
    }

    public function test_list_compares_with_the_local_course(): void
    {
        $gleich = $this->fahrt();
        $anders = $this->fahrt('07:00:00', '07:30:00');
        $ohne = $this->fahrt('08:00:00', '08:30:00');
        $this->kurs($gleich, '03');
        $this->kurs($anders, '05');

        $this->sichtung($gleich, '3');
        $this->sichtung($anders);
        $this->sichtung($ohne);
        $this->sichtung(null, werte: ['match' => SightingMatch::NoTrip]);

        $jeFahrt = [];

        foreach ($this->liste() as $z) {
            $jeFahrt[$z['trip']['id'] ?? 0] = $z;
        }

        $this->assertSame('same', $jeFahrt[$gleich->id]['comparison']);
        $this->assertSame('differs', $jeFahrt[$anders->id]['comparison']);
        $this->assertSame('1/05', $jeFahrt[$anders->id]['local_course']['display']);
        $this->assertSame('none', $jeFahrt[$ohne->id]['comparison']);
        $this->assertSame('no_trip', $jeFahrt[0]['comparison']);
        $this->assertSame('mo_fr', $jeFahrt[$ohne->id]['trip']['day_type']);
    }

    public function test_list_differs_only(): void
    {
        $anders = $this->fahrt();
        $gleich = $this->fahrt('07:00:00', '07:30:00');
        $this->kurs($anders, '05');
        $this->kurs($gleich, '03');
        $treffer = $this->sichtung($anders);
        $this->sichtung($gleich, '003');
        $this->sichtung($this->fahrt('08:00:00', '08:30:00'));

        $this->assertSame([$treffer->id], array_column($this->liste(['differs_only' => 'true']), 'id'));
    }

    public function test_list_filters_by_line_and_date(): void
    {
        $treffer = $this->sichtung($this->fahrt(), werte: ['service_date' => '2026-09-02']);
        $this->sichtung($this->fahrt('07:00:00', '07:30:00'), werte: ['service_date' => '2026-08-20']);
        $this->sichtung($this->fahrt('08:00:00', '08:30:00'), werte: ['service_date' => '2026-09-02', 'line' => '9']);

        $ids = array_column($this->liste(['line' => '1', 'date_from' => '2026-09-01', 'date_to' => '2026-09-03']), 'id');

        $this->assertSame([$treffer->id], $ids);
    }

    public function test_counts(): void
    {
        $this->sichtung($this->fahrt());
        $this->sichtung(null);
        $this->sichtung(null);

        $this->withToken($this->token())->getJson('/api/v1/admin/sightings/counts')
            ->assertOk()
            ->assertExactJson(['data' => ['open' => 1, 'waiting' => 2]]);
    }

    public function test_accept_sets_the_course_on_a_trip_without_course(): void
    {
        $fahrt = $this->fahrt();
        $s = $this->sichtung($fahrt);

        $this->withToken($this->token())->postJson('/api/v1/admin/sightings/accept', ['ids' => [$s->id]])
            ->assertOk()
            ->assertJsonPath('data.accepted', 1)
            ->assertJsonPath('data.trips_assigned', 1);

        $this->assertSame(SightingStatus::Accepted, $s->refresh()->status);
        $this->assertNotNull($s->decided_at);
        $this->assertDatabaseHas('courses', ['number' => '03', 'period_id' => $this->version->period_id]);
    }

    /**
     * Konflikt: Die ganze Kette wird umnummeriert, und eine zweite offene Sichtung derselben
     * Kette, die schon die neue Nummer nennt, gilt danach als bestätigt.
     */
    public function test_accept_renumbers_the_whole_chain_and_confirms_others(): void
    {
        $a = $this->fahrt();
        $b = $this->f->fahrt($this->version, ['B', 'A'], ['06:35:00', '07:05:00']);
        TripLink::factory()->create([
            'from_trip_id' => $a->id,
            'to_trip_id' => $b->id,
            'stop_id' => $a->last_stop_id,
            'kind' => 'link',
        ]);
        $alt = $this->kurs($a, '03');
        CourseTrip::factory()->create(['course_id' => $alt->id, 'consolidated_trip_id' => $b->id]);

        $s = $this->sichtung($a, '04');
        $andere = $this->sichtung($b, '04');

        $zeile = collect($this->liste())->firstWhere('id', $s->id);
        $this->assertSame('differs', $zeile['comparison']);
        $this->assertSame(2, $zeile['chain_trip_count']);

        $this->withToken($this->token())->postJson('/api/v1/admin/sightings/accept', ['ids' => [$s->id]])
            ->assertOk()
            ->assertJsonPath('data.trips_assigned', 2)
            ->assertJsonPath('data.confirmed_others', 1);

        $nummern = CourseTrip::query()->with('course')->whereIn('consolidated_trip_id', [$a->id, $b->id])->get()
            ->map(fn (CourseTrip $k): string => $k->course->number)->unique()->values()->all();
        $this->assertSame(['04'], $nummern);
        $this->assertSame(SightingStatus::Confirmed, $andere->refresh()->status);
    }

    public function test_accept_without_trip_is_refused(): void
    {
        $s = $this->sichtung(null, werte: ['match' => SightingMatch::NoTrip]);

        $this->withToken($this->token())->postJson('/api/v1/admin/sightings/accept', ['ids' => [$s->id]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);

        $this->assertSame(SightingStatus::Pending, $s->refresh()->status);
    }

    public function test_accept_conflicting_numbers_for_one_trip_is_refused(): void
    {
        $fahrt = $this->fahrt();
        $eins = $this->sichtung($fahrt, '03');
        $zwei = $this->sichtung($fahrt, '04');

        $this->withToken($this->token())->postJson('/api/v1/admin/sightings/accept', ['ids' => [$eins->id, $zwei->id]])
            ->assertStatus(422);

        $this->assertDatabaseCount('courses', 0);
    }

    public function test_accept_twice_is_refused(): void
    {
        $s = $this->sichtung($this->fahrt(), werte: ['status' => SightingStatus::Accepted]);

        $this->withToken($this->token())->postJson('/api/v1/admin/sightings/accept', ['ids' => [$s->id]])
            ->assertStatus(422);
    }

    public function test_reject_with_note(): void
    {
        $s = $this->sichtung(null, werte: ['match' => SightingMatch::NoTrip]);

        $this->withToken($this->token())
            ->postJson('/api/v1/admin/sightings/reject', ['ids' => [$s->id], 'note' => 'Betriebsfahrt'])
            ->assertOk()
            ->assertJsonPath('data.rejected', 1);

        $s->refresh();
        $this->assertSame(SightingStatus::Rejected, $s->status);
        $this->assertSame('Betriebsfahrt', $s->decision_note);
        $this->assertDatabaseCount('courses', 0);
    }

    public function test_reject_unknown_id_is_refused(): void
    {
        $this->withToken($this->token())->postJson('/api/v1/admin/sightings/reject', ['ids' => [999]])
            ->assertStatus(422);
    }

    /**
     * Der Fahrplan zeigt je Spalte die offenen Sichtungen, gruppiert nach Nummer — die
     * meistgenannte zuerst. Entschiedene und wartende fehlen.
     */
    public function test_timetable_shows_pending_sightings_grouped(): void
    {
        $fahrt = $this->fahrt();
        $this->kurs($fahrt, '03');
        $this->sichtung($fahrt, '04', ['service_date' => '2026-09-01']);
        $this->sichtung($fahrt, '4', ['service_date' => '2026-09-02']);
        $this->sichtung($fahrt, '03', ['match' => SightingMatch::MatchedNextVersion]);
        $this->sichtung($fahrt, '07', ['status' => SightingStatus::Rejected]);
        $this->fahrt('07:00:00', '07:30:00');

        $spalten = $this->withToken($this->token())
            ->getJson("/api/v1/admin/line-versions/{$this->version->id}/timetable")
            ->assertOk()
            ->json('data.directions.0.trips');

        $gruppen = collect($spalten)->firstWhere('id', $fahrt->id)['sightings'];

        $this->assertCount(2, $gruppen);
        $this->assertSame('1/04', $gruppen[0]['display']);
        $this->assertSame(2, $gruppen[0]['count']);
        $this->assertSame(['2026-09-01', '2026-09-02'], $gruppen[0]['dates']);
        $this->assertSame('differs', $gruppen[0]['comparison']);
        $this->assertSame(1, $gruppen[0]['chain_trip_count']);
        $this->assertSame('same', $gruppen[1]['comparison']);
        $this->assertTrue($gruppen[1]['next_version']);
        $this->assertSame([], collect($spalten)->firstWhere('id', '!=', $fahrt->id)['sightings']);
    }
}
