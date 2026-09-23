<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Models\ConsolidatedTrip;
use App\Models\Course;
use App\Models\CourseTrip;
use App\Models\Depot;
use App\Models\LineVersion;
use App\Models\SchedulePeriod;
use App\Models\TripLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Übernahme gepflegter Kurse und Anschlüsse beim Versionswechsel (KURSE §2 K4).
 */
final class CourseCarryoverTest extends TestCase
{
    use RefreshDatabase;

    private ConsolidatedFixtures $f;

    private SchedulePeriod $periode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new ConsolidatedFixtures;
        $this->periode = $this->f->periode();
    }

    private function token(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    private function version(string $linie, int $versionNo): LineVersion
    {
        $version = $this->f->version($linie, FahrplanTyp::MoFrNormal, $versionNo, $this->periode);
        $this->f->gueltigkeit($version);

        return $version;
    }

    private function vorschau(LineVersion $von, LineVersion $nach): TestResponse
    {
        return $this->withToken($this->token())
            ->getJson("/api/v1/admin/line-versions/{$nach->id}/course-carryover?from={$von->id}");
    }

    private function uebernimm(LineVersion $von, LineVersion $nach): TestResponse
    {
        return $this->withToken($this->token())
            ->postJson("/api/v1/admin/line-versions/{$nach->id}/course-carryover", ['from' => $von->id]);
    }

    private function setzeKurs(ConsolidatedTrip $trip, string $nummer): void
    {
        $this->withToken($this->token())
            ->putJson("/api/v1/admin/consolidated-trips/{$trip->id}/course", ['number' => $nummer])
            ->assertOk();
    }

    private function verknuepfe(ConsolidatedTrip $von, ConsolidatedTrip $nach): void
    {
        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'link',
            'from_trip_id' => $von->id,
            'to_trip_id' => $nach->id,
        ])->assertCreated();
    }

    public function test_requires_authentication(): void
    {
        $alt = $this->version('1', 1);
        $neu = $this->version('1', 2);

        $this->getJson("/api/v1/admin/line-versions/{$neu->id}/course-carryover?from={$alt->id}")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_versions_of_different_lines_are_rejected(): void
    {
        $eins = $this->version('1', 1);
        $dreizehn = $this->version('13', 1);

        $this->vorschau($eins, $dreizehn)->assertStatus(422)->assertJsonPath('error.code', 422);
    }

    public function test_the_same_version_as_source_and_target_is_rejected(): void
    {
        $version = $this->version('1', 1);

        $this->vorschau($version, $version)->assertStatus(422)->assertJsonPath('error.code', 422);
    }

    /** Die Vorschau muss folgenlos sein — sonst wäre sie keine. */
    public function test_the_preview_changes_nothing(): void
    {
        $alt = $this->version('1', 1);
        $neu = $this->version('1', 2);

        $alteFahrt = $this->f->fahrt($alt, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-gleich');
        $this->f->fahrt($neu, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-gleich');

        $this->setzeKurs($alteFahrt, '03');
        $vorher = CourseTrip::query()->count();

        $daten = $this->vorschau($alt, $neu)->assertOk()->json('data');

        $this->assertSame(1, $daten['summary']['courses_carried'], 'Die Vorschau sagt, was passieren wuerde');
        $this->assertSame($vorher, CourseTrip::query()->count(), 'Aber sie tut es nicht');
    }

    public function test_the_course_is_carried_to_an_unchanged_trip(): void
    {
        $alt = $this->version('1', 1);
        $neu = $this->version('1', 2);

        $alteFahrt = $this->f->fahrt($alt, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-gleich');
        $neueFahrt = $this->f->fahrt($neu, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-gleich');

        $this->setzeKurs($alteFahrt, '03');

        $daten = $this->uebernimm($alt, $neu)->assertOk()->json('data');

        $this->assertSame(1, $daten['summary']['courses_carried']);
        $this->assertDatabaseHas('course_trips', ['consolidated_trip_id' => $neueFahrt->id]);
    }

    /**
     * Auch eine **verschobene** Fahrt bekommt den Kurs: Dass sich ihre Zeit geändert hat, macht
     * sie nicht zu einem anderen Umlauf. Nur ihre Anschlüsse sind dann neu zu prüfen.
     */
    public function test_the_course_is_carried_to_a_shifted_trip(): void
    {
        $alt = $this->version('1', 1);
        $neu = $this->version('1', 2);

        $alteFahrt = $this->f->fahrt($alt, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-alt');
        $neueFahrt = $this->f->fahrt($neu, ['A', 'B'], ['06:05:00', '06:35:00'], 'sig-neu');

        $this->setzeKurs($alteFahrt, '03');

        $daten = $this->uebernimm($alt, $neu)->assertOk()->json('data');

        $this->assertSame(1, $daten['summary']['changed'], 'Die Fahrt gilt als verschoben');
        $this->assertSame(1, $daten['summary']['courses_carried']);
        $this->assertDatabaseHas('course_trips', ['consolidated_trip_id' => $neueFahrt->id]);
    }

    /** Eine Fahrt ohne Vorgängerin in der alten Version bleibt ohne Kurs. */
    public function test_an_added_trip_stays_without_a_course(): void
    {
        $alt = $this->version('1', 1);
        $neu = $this->version('1', 2);

        $alteFahrt = $this->f->fahrt($alt, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-gleich');
        $this->f->fahrt($neu, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-gleich');
        $zusatz = $this->f->fahrt($neu, ['A', 'B'], ['14:00:00', '14:30:00'], 'sig-neu-zusatz');

        $this->setzeKurs($alteFahrt, '03');

        $daten = $this->uebernimm($alt, $neu)->assertOk()->json('data');

        $this->assertSame(1, $daten['summary']['added']);
        $this->assertDatabaseMissing('course_trips', ['consolidated_trip_id' => $zusatz->id]);
    }

    /** Ein Anschluss, dessen beide Enden in dieser Version liegen, wird mitgenommen. */
    public function test_a_link_inside_the_version_is_carried(): void
    {
        $alt = $this->version('1', 1);
        $neu = $this->version('1', 2);

        $altA = $this->f->fahrt($alt, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-a');
        $altB = $this->f->fahrt($alt, ['B', 'A'], ['06:35:00', '07:05:00'], 'sig-b');
        $neuA = $this->f->fahrt($neu, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-a');
        $neuB = $this->f->fahrt($neu, ['B', 'A'], ['06:35:00', '07:05:00'], 'sig-b');

        $this->verknuepfe($altA, $altB);

        $daten = $this->uebernimm($alt, $neu)->assertOk()->json('data');

        $this->assertSame(1, $daten['summary']['links_carried']);
        $this->assertDatabaseHas('trip_links', [
            'from_trip_id' => $neuA->id,
            'to_trip_id' => $neuB->id,
            'kind' => 'link',
        ]);
    }

    /** Aus- und Einrücken hängen an einer Fahrt allein und lassen sich deshalb immer übertragen. */
    public function test_terminal_decisions_are_carried(): void
    {
        $alt = $this->version('1', 1);
        $neu = $this->version('1', 2);

        $alteFahrt = $this->f->fahrt($alt, ['Betriebshof', 'A'], ['04:30:00', '04:42:00'], 'sig-gleich');
        $neueFahrt = $this->f->fahrt($neu, ['Betriebshof', 'A'], ['04:30:00', '04:42:00'], 'sig-gleich');

        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'start',
            'to_trip_id' => $alteFahrt->id,
            'note' => 'Ausrücken',
        ])->assertCreated();

        $daten = $this->uebernimm($alt, $neu)->assertOk()->json('data');

        $this->assertSame(1, $daten['summary']['terminals_carried']);
        $this->assertDatabaseHas('trip_links', [
            'to_trip_id' => $neueFahrt->id,
            'kind' => 'start',
            'note' => 'Ausrücken',
        ]);
    }

    /**
     * Der Betriebshof geht mit: Ein Fahrplanwechsel ändert nicht, aus welchem Hof ein Umlauf
     * ausrückt. Bliebe er liegen, wäre die Angabe nach jedem Wechsel von Hand nachzutragen.
     */
    public function test_the_depot_is_carried_with_the_terminal_decision(): void
    {
        $alt = $this->version('1', 1);
        $neu = $this->version('1', 2);

        $alteFahrt = $this->f->fahrt($alt, ['Betriebshof', 'A'], ['04:30:00', '04:42:00'], 'sig-gleich');
        $neueFahrt = $this->f->fahrt($neu, ['Betriebshof', 'A'], ['04:30:00', '04:42:00'], 'sig-gleich');

        $hof = Depot::factory()->create(['name' => 'Nord-Hof']);

        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'start',
            'to_trip_id' => $alteFahrt->id,
            'depot_id' => $hof->id,
        ])->assertCreated();

        $this->uebernimm($alt, $neu)->assertOk();

        $this->assertDatabaseHas('trip_links', [
            'to_trip_id' => $neueFahrt->id,
            'kind' => 'start',
            'depot_id' => $hof->id,
        ]);
    }

    /**
     * Der Linienwechsel-Fall: Der Anschluss zeigt auf eine Fahrt **außerhalb** dieser Version.
     * Kopieren geht nicht — die Gegenfahrt hängt noch an der alten Fahrt, und ein Fahrzeug hat
     * höchstens einen Vorgänger. Gemeldet statt still übergangen.
     */
    /**
     * **Ein Anschluss auf eine fremde Linie geht jetzt mit** — wenn die beiden Versionen an
     * verschiedenen Tagen gelten.
     *
     * Die Gegenfahrt bleibt, wo sie ist, und bekommt einen **zweiten** Anschluss auf die Fahrt
     * der neuen Version: Vor dem Wechseltag fährt das Fahrzeug auf die alte weiter, danach auf
     * die neue. Bis zum 23.09.2026 stand dem ein Unique-Constraint im Weg, und dieser Fall
     * landete unter „blockiert" mit der Bitte, ihn von Hand zu setzen (KURSE §3).
     */
    public function test_a_link_to_another_line_is_carried_when_the_days_differ(): void
    {
        $alt = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $this->periode);
        $this->f->gueltigkeit($alt, '2026-08-17', '2026-08-21');

        $neu = $this->f->version('1', FahrplanTyp::MoFrNormal, 2, $this->periode);
        $this->f->gueltigkeit($neu, '2026-08-24', '2026-08-28');

        // Die 13 laeuft durchgehend — sie ist die Gegenfahrt, die stehen bleibt.
        $andere = $this->f->version('13', FahrplanTyp::MoFrNormal, 1, $this->periode);
        $this->f->gueltigkeit($andere, '2026-08-17', '2026-08-28');

        $alteFahrt = $this->f->fahrt($alt, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00'], 'sig-gleich');
        $neueFahrt = $this->f->fahrt($neu, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00'], 'sig-gleich');
        $fremdeFahrt = $this->f->fahrt($andere, ['Sudenburg', 'Westerhüsen'], ['06:52:00', '07:24:00'], 'sig-13');

        $this->verknuepfe($alteFahrt, $fremdeFahrt);

        $daten = $this->uebernimm($alt, $neu)->assertOk()->json('data');

        $this->assertSame(1, $daten['summary']['links_carried']);
        $this->assertSame(0, $daten['summary']['blocked']);

        // Beide Anschlüsse stehen nebeneinander, jeder für seine Tage.
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $alteFahrt->id, 'to_trip_id' => $fremdeFahrt->id]);
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $neueFahrt->id, 'to_trip_id' => $fremdeFahrt->id]);
    }

    /**
     * Gelten beide Versionen an denselben Tagen, bleibt der bestehende Anschluss stehen: An
     * einem Tag hat das Fahrzeug genau einen Vorgänger. Der Fall wird gemeldet, nicht
     * stillschweigend übergangen.
     */
    public function test_a_carried_link_on_the_same_days_is_reported(): void
    {
        $alt = $this->version('1', 1);
        $neu = $this->version('1', 2);
        $andere = $this->version('13', 1);

        $alteFahrt = $this->f->fahrt($alt, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00'], 'sig-gleich');
        $this->f->fahrt($neu, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00'], 'sig-gleich');
        $fremdeFahrt = $this->f->fahrt($andere, ['Sudenburg', 'Westerhüsen'], ['06:52:00', '07:24:00'], 'sig-13');

        $this->verknuepfe($alteFahrt, $fremdeFahrt);

        $daten = $this->uebernimm($alt, $neu)->assertOk()->json('data');

        $this->assertSame(0, $daten['summary']['links_carried']);
        $this->assertSame(1, $daten['summary']['blocked']);
        $this->assertStringContainsString('denselben Tagen', $daten['blocked'][0]['reason']);

        // Der alte Anschluss bleibt unangetastet.
        $this->assertDatabaseHas('trip_links', [
            'from_trip_id' => $alteFahrt->id,
            'to_trip_id' => $fremdeFahrt->id,
        ]);
    }

    /** Was an entfallenen Fahrten hing, geht verloren — die Vorschau sagt es vorher. */
    public function test_decisions_on_removed_trips_are_reported_as_lost(): void
    {
        $alt = $this->version('1', 1);
        $neu = $this->version('1', 2);

        $bleibt = $this->f->fahrt($alt, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-gleich');
        $entfaellt = $this->f->fahrt($alt, ['A', 'B'], ['23:00:00', '23:30:00'], 'sig-weg');
        $this->f->fahrt($neu, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-gleich');

        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'end',
            'from_trip_id' => $entfaellt->id,
        ])->assertCreated();

        $daten = $this->vorschau($alt, $neu)->assertOk()->json('data');

        $this->assertSame(1, $daten['summary']['removed']);
        $this->assertSame(1, $daten['summary']['lost']);
        $this->assertSame('end', $daten['lost'][0]['kind']);
        $this->assertNotNull($bleibt->id);
    }

    /** Eine schon gesetzte Pflege gewinnt: Sie ist die jüngere Aussage. */
    public function test_existing_maintenance_on_the_new_version_is_not_overwritten(): void
    {
        $alt = $this->version('1', 1);
        $neu = $this->version('1', 2);

        $alteFahrt = $this->f->fahrt($alt, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-gleich');
        $neueFahrt = $this->f->fahrt($neu, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-gleich');

        $this->setzeKurs($alteFahrt, '03');
        $this->setzeKurs($neueFahrt, '09');

        $daten = $this->uebernimm($alt, $neu)->assertOk()->json('data');

        $this->assertSame(0, $daten['summary']['courses_carried']);
        $this->assertSame(
            '09',
            Course::query()->findOrFail(
                CourseTrip::query()->where('consolidated_trip_id', $neueFahrt->id)->value('course_id')
            )->number,
        );
    }

    /** Zweimal übernehmen darf nichts doppeln — die Unique-Regeln würden es sonst abweisen. */
    public function test_applying_twice_is_idempotent(): void
    {
        $alt = $this->version('1', 1);
        $neu = $this->version('1', 2);

        $altA = $this->f->fahrt($alt, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-a');
        $altB = $this->f->fahrt($alt, ['B', 'A'], ['06:35:00', '07:05:00'], 'sig-b');
        $this->f->fahrt($neu, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-a');
        $this->f->fahrt($neu, ['B', 'A'], ['06:35:00', '07:05:00'], 'sig-b');

        $this->verknuepfe($altA, $altB);
        $this->setzeKurs($altA, '03');

        $this->uebernimm($alt, $neu)->assertOk();
        $nachErstem = [TripLink::query()->count(), CourseTrip::query()->count()];

        $this->uebernimm($alt, $neu)->assertOk();

        $this->assertSame($nachErstem, [TripLink::query()->count(), CourseTrip::query()->count()]);
    }

    /**
     * Der Periodenwechsel ist genau der Moment, in dem die Übernahme gebraucht wird: Er setzt
     * jede (Linie, Fahrplantyp) auf Version 1 zurück. Wäre er gesperrt, wäre die gesamte Kurs-
     * und Anschlusspflege mit ihm verloren (entschieden 21.09.2026).
     */
    public function test_courses_are_carried_across_a_period_boundary(): void
    {
        $alt = $this->version('1', 2);

        $neuePeriode = SchedulePeriod::factory()->create([
            'label' => 'Nachfolgerin',
            'valid_from' => '2026-09-21',
        ]);
        $neu = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $neuePeriode);
        $this->f->gueltigkeit($neu);

        $alteFahrt = $this->f->fahrt($alt, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-gleich');
        $neueFahrt = $this->f->fahrt($neu, ['A', 'B'], ['06:00:00', '06:30:00'], 'sig-gleich');

        $this->setzeKurs($alteFahrt, '03');

        $daten = $this->uebernimm($alt, $neu)->assertOk()->json('data');

        $this->assertSame(1, $daten['summary']['courses_carried']);
        $this->assertDatabaseHas('course_trips', ['consolidated_trip_id' => $neueFahrt->id]);
    }
}
