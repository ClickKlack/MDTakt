<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Models\ConsolidatedTrip;
use App\Models\Course;
use App\Models\CourseTrip;
use App\Models\LineVersion;
use App\Models\SchedulePeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Kursnummern: das Etikett am Umlauf (KURSE §2 K1–K3).
 */
final class CourseTest extends TestCase
{
    use RefreshDatabase;

    private ConsolidatedFixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new ConsolidatedFixtures;
    }

    private function token(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    private function version(string $linie = '1', ?SchedulePeriod $periode = null): LineVersion
    {
        $version = $this->f->version($linie, FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($version);

        return $version;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function setzeKurs(ConsolidatedTrip $trip, array $body): TestResponse
    {
        return $this->withToken($this->token())
            ->putJson("/api/v1/admin/consolidated-trips/{$trip->id}/course", $body);
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
        $this->getJson('/api/v1/admin/courses')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_assigning_by_number_creates_the_course(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['A', 'B'], ['06:14:00', '06:48:00']);

        $daten = $this->setzeKurs($fahrt, ['number' => '03'])->assertOk()->json('data');

        $this->assertSame('03', $daten['course']['number']);
        $this->assertSame(1, $daten['trips_assigned']);
        $this->assertSame(['1'], $daten['course']['lines']);
        $this->assertDatabaseHas('courses', ['number' => '03', 'day_type' => 'mo_fr']);
    }

    /**
     * Der Kern von K2: Die Nummer gehört dem Umlauf. Wer sie an einer Fahrt setzt, setzt sie
     * für die ganze Kette — welche Fahrt angeklickt wurde, ist gleichgültig.
     */
    public function test_the_course_applies_to_the_whole_chain(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);
        $c = $this->f->fahrt($version, ['A', 'B'], ['07:10:00', '07:40:00']);

        $this->verknuepfe($a, $b);
        $this->verknuepfe($b, $c);

        // Gesetzt an der mittleren Fahrt — gelten muss er für alle drei.
        $daten = $this->setzeKurs($b, ['number' => '07'])->assertOk()->json('data');

        $this->assertSame(3, $daten['trips_assigned']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $daten['trip_ids']);

        foreach ([$a, $b, $c] as $fahrt) {
            $this->assertDatabaseHas('course_trips', ['consolidated_trip_id' => $fahrt->id]);
        }
    }

    /**
     * Der Fall aus K1: Eine 1 wird in Sudenburg zur 13. Die Nummer bleibt, der Linien-Präfix
     * wechselt — der Umlauf umfasst beide Linien.
     */
    public function test_a_course_spans_the_lines_of_its_chain(): void
    {
        $periode = $this->f->periode();
        $eins = $this->version('1', $periode);
        $dreizehn = $this->version('13', $periode);

        $aufEins = $this->f->fahrt($eins, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);
        $aufDreizehn = $this->f->fahrt($dreizehn, ['Sudenburg', 'Westerhüsen'], ['06:52:00', '07:24:00']);

        $this->verknuepfe($aufEins, $aufDreizehn);
        $this->setzeKurs($aufEins, ['number' => '03'])->assertOk();

        $kurse = $this->withToken($this->token())
            ->getJson("/api/v1/admin/courses?period={$periode->id}&day_type=mo_fr")
            ->assertOk()->json('data');

        $this->assertCount(1, $kurse);
        $this->assertSame(['1', '13'], $kurse[0]['lines']);
        $this->assertSame(2, $kurse[0]['trip_count']);
    }

    public function test_filtering_by_line_finds_courses_that_touch_it(): void
    {
        $periode = $this->f->periode();
        $eins = $this->version('1', $periode);
        $dreizehn = $this->version('13', $periode);

        $aufEins = $this->f->fahrt($eins, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);
        $aufDreizehn = $this->f->fahrt($dreizehn, ['Sudenburg', 'Westerhüsen'], ['06:52:00', '07:24:00']);

        $this->verknuepfe($aufEins, $aufDreizehn);
        $this->setzeKurs($aufEins, ['number' => '03'])->assertOk();

        // Der Umlauf erscheint bei beiden Linien — er berührt beide.
        foreach (['1', '13'] as $linie) {
            $treffer = $this->withToken($this->token())
                ->getJson("/api/v1/admin/courses?period={$periode->id}&day_type=mo_fr&line={$linie}")
                ->assertOk()->json('data');

            $this->assertCount(1, $treffer, "Linie {$linie} findet den Umlauf nicht");
        }

        $fremd = $this->withToken($this->token())
            ->getJson("/api/v1/admin/courses?period={$periode->id}&day_type=mo_fr&line=9")
            ->assertOk()->json('data');

        $this->assertSame([], $fremd);
    }

    /**
     * Zwei verknüpfte Fahrten sind dasselbe Fahrzeug — also derselbe Kurs. Trägt eine Seite
     * bereits eine Nummer, muss die Verknüpfung sie übernehmen: Das von Hand nachzutragen wäre
     * Arbeit, die aus der Verknüpfung schon folgt.
     */
    public function test_linking_carries_the_course_to_the_other_side(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);

        $this->setzeKurs($a, ['number' => '03'])->assertOk();

        $daten = $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'link',
            'from_trip_id' => $a->id,
            'to_trip_id' => $b->id,
        ])->assertCreated()->json('data');

        $this->assertSame('03', $daten['course']['number']);
        $this->assertSame(1, $daten['course_trips_assigned'], 'Genau die andere Seite kam dazu');
        $this->assertSame(2, CourseTrip::query()->count());
    }

    /** Die Richtung ist gleichgültig: Auch ein Kurs auf der Nachfolgefahrt gilt danach für beide. */
    public function test_linking_carries_the_course_backwards_too(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);

        $this->setzeKurs($b, ['number' => '07'])->assertOk();

        $daten = $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'link',
            'from_trip_id' => $a->id,
            'to_trip_id' => $b->id,
        ])->assertCreated()->json('data');

        $this->assertSame('07', $daten['course']['number']);
        $this->assertDatabaseHas('course_trips', ['consolidated_trip_id' => $a->id]);
    }

    /** Eine ganze Kette erbt den Kurs, nicht nur die unmittelbar angehängte Fahrt. */
    public function test_linking_carries_the_course_to_a_whole_chain(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);
        $c = $this->f->fahrt($version, ['A', 'B'], ['07:10:00', '07:40:00']);

        // Erst b–c verketten, dann a mit dem Kurs davorhaengen.
        $this->verknuepfe($b, $c);
        $this->setzeKurs($a, ['number' => '03'])->assertOk();

        $daten = $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'link',
            'from_trip_id' => $a->id,
            'to_trip_id' => $b->id,
        ])->assertCreated()->json('data');

        $this->assertSame(2, $daten['course_trips_assigned'], 'b und c kamen dazu');
        $this->assertSame(3, CourseTrip::query()->count());
    }

    /**
     * Tragen beide Seiten bereits **verschiedene** Nummern, darf nichts überschrieben werden —
     * welche die richtige ist, weiß nur der Pflegende. Die Verknüpfung bleibt trotzdem
     * bestehen: Sie ist eine Aussage über das Fahrzeug, der Kurs nur sein Etikett.
     */
    public function test_conflicting_courses_are_reported_not_overwritten(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);

        $this->setzeKurs($a, ['number' => '03'])->assertOk();
        $this->setzeKurs($b, ['number' => '07'])->assertOk();

        $daten = $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'link',
            'from_trip_id' => $a->id,
            'to_trip_id' => $b->id,
        ])->assertCreated()->json('data');

        $this->assertContains('course_conflict', array_column($daten['warnings'], 'code'));
        $this->assertNull($daten['course']);

        // Nichts wurde umgeschrieben.
        $this->assertSame(2, Course::query()->count());
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $a->id, 'to_trip_id' => $b->id]);
    }

    /** Trägt keine Seite einen Kurs, gibt es nichts zu übertragen — und keine Warnung. */
    public function test_linking_without_any_course_reports_nothing(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);

        $daten = $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'link',
            'from_trip_id' => $a->id,
            'to_trip_id' => $b->id,
        ])->assertCreated()->json('data');

        $this->assertNull($daten['course']);
        $this->assertSame(0, $daten['course_trips_assigned']);
        $this->assertSame([], $daten['warnings']);
    }

    /**
     * Der Kurs läuft über den Linienwechsel mit — genau der Fall, für den er am Umlauf hängt
     * und nicht an der Linie (K1).
     */
    public function test_linking_carries_the_course_across_a_line_change(): void
    {
        $periode = $this->f->periode();
        $eins = $this->version('1', $periode);
        $dreizehn = $this->version('13', $periode);

        $aufEins = $this->f->fahrt($eins, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);
        $aufDreizehn = $this->f->fahrt($dreizehn, ['Sudenburg', 'Westerhüsen'], ['06:52:00', '07:24:00']);

        $this->setzeKurs($aufEins, ['number' => '03'])->assertOk();

        $daten = $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'link',
            'from_trip_id' => $aufEins->id,
            'to_trip_id' => $aufDreizehn->id,
        ])->assertCreated()->json('data');

        $this->assertSame('03', $daten['course']['number']);
        $this->assertSame(['1', '13'], $daten['course']['lines']);
    }

    public function test_assigning_an_existing_course_by_id(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['A', 'B'], ['06:14:00', '06:48:00']);

        $kurs = Course::query()->create([
            'period_id' => $version->period_id,
            'day_type' => 'mo_fr',
            'number' => '12',
        ]);

        $this->setzeKurs($fahrt, ['course_id' => $kurs->id])
            ->assertOk()
            ->assertJsonPath('data.course.number', '12');
    }

    public function test_assigning_the_same_number_twice_reuses_the_course(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['C', 'D'], ['08:00:00', '08:30:00']);

        $ersteId = $this->setzeKurs($a, ['number' => '05'])->assertOk()->json('data.course.id');
        $zweiteId = $this->setzeKurs($b, ['number' => '05'])->assertOk()->json('data.course.id');

        $this->assertSame($ersteId, $zweiteId, 'Dieselbe Nummer im selben Strang ist derselbe Umlauf');
        $this->assertSame(1, Course::query()->count());
    }

    /**
     * K3: Zwei Umläufe mit derselben Nummer sind kein Fehler — aber sie fallen auf.
     */
    public function test_a_duplicate_number_is_reported_not_rejected(): void
    {
        $version = $this->version();
        $periode = $version->period_id;

        Course::query()->create(['period_id' => $periode, 'day_type' => 'mo_fr', 'number' => '03']);
        $this->withToken($this->token())->postJson('/api/v1/admin/courses', [
            'period_id' => $periode,
            'day_type' => 'mo_fr',
            'number' => '03',
        ])->assertCreated()->assertJsonPath('data.duplicate', true);

        $this->assertSame(2, Course::query()->where('number', '03')->count());
    }

    public function test_the_same_number_in_another_strand_is_a_different_course(): void
    {
        $periode = $this->f->periode();

        $werktag = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($werktag);
        $sonntag = $this->f->version('1', FahrplanTyp::SoFeiertag, 1, $periode);
        $this->f->gueltigkeit($sonntag);

        $werktagsfahrt = $this->f->fahrt($werktag, ['A', 'B'], ['06:14:00', '06:48:00']);
        $sonntagsfahrt = $this->f->fahrt($sonntag, ['A', 'B'], ['08:14:00', '08:48:00']);

        $a = $this->setzeKurs($werktagsfahrt, ['number' => '03'])->assertOk()->json('data.course.id');
        $b = $this->setzeKurs($sonntagsfahrt, ['number' => '03'])->assertOk()->json('data.course.id');

        $this->assertNotSame($a, $b, 'Mo-Fr und So sind getrennte Straenge');
        // Und keiner der beiden gilt als Dublette — sie stehen in verschiedenen Straengen.
        $this->assertFalse(Course::query()->findOrFail($a)->is(Course::query()->findOrFail($b)));
    }

    public function test_a_course_from_another_strand_is_rejected(): void
    {
        $periode = $this->f->periode();
        $werktag = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($werktag);
        $fahrt = $this->f->fahrt($werktag, ['A', 'B'], ['06:14:00', '06:48:00']);

        $fremderKurs = Course::query()->create([
            'period_id' => $periode->id,
            'day_type' => 'so_feiertag',
            'number' => '03',
        ]);

        $this->setzeKurs($fahrt, ['course_id' => $fremderKurs->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_neither_number_nor_id_is_rejected(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['A', 'B'], ['06:14:00', '06:48:00']);

        $this->setzeKurs($fahrt, [])->assertStatus(422)->assertJsonPath('error.code', 422);
    }

    public function test_both_number_and_id_is_rejected(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['A', 'B'], ['06:14:00', '06:48:00']);

        $kurs = Course::query()->create([
            'period_id' => $version->period_id,
            'day_type' => 'mo_fr',
            'number' => '12',
        ]);

        $this->setzeKurs($fahrt, ['course_id' => $kurs->id, 'number' => '13'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_detaching_removes_the_course_from_the_whole_chain(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);

        $this->verknuepfe($a, $b);
        $this->setzeKurs($a, ['number' => '03'])->assertOk();

        $daten = $this->withToken($this->token())
            ->deleteJson("/api/v1/admin/consolidated-trips/{$b->id}/course")
            ->assertOk()->json('data');

        $this->assertNull($daten['course']);
        $this->assertSame(2, $daten['trips_assigned']);
        $this->assertSame(0, CourseTrip::query()->count());

        // Der Umlauf selbst bleibt — seine Nummer ist nicht verbraucht.
        $this->assertSame(1, Course::query()->count());
    }

    public function test_reassigning_moves_the_chain_to_the_other_course(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);
        $this->verknuepfe($a, $b);

        $this->setzeKurs($a, ['number' => '03'])->assertOk();
        $neu = $this->setzeKurs($a, ['number' => '09'])->assertOk()->json('data.course.id');

        $this->assertSame(2, CourseTrip::query()->where('course_id', $neu)->count());
        $this->assertSame(2, CourseTrip::query()->count(), 'Eine Fahrt gehoert zu hoechstens einem Umlauf');
    }

    public function test_deleting_a_course_keeps_trips_and_links(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);
        $this->verknuepfe($a, $b);

        $kursId = $this->setzeKurs($a, ['number' => '03'])->assertOk()->json('data.course.id');

        $this->withToken($this->token())
            ->deleteJson("/api/v1/admin/courses/{$kursId}")
            ->assertNoContent();

        $this->assertSame(0, CourseTrip::query()->count());
        $this->assertSame(1, \App\Models\TripLink::query()->count(), 'Die Verkettung bleibt');
        $this->assertNotNull(ConsolidatedTrip::query()->find($a->id));
    }

    /**
     * Die Eckzeiten eines Umlaufs folgen dem Betriebstag, nicht der Uhr: Auf der N1 beginnt
     * er abends und endet am Morgen darauf.
     */
    public function test_course_span_follows_the_operating_day(): void
    {
        $version = $this->version('N1');
        $abends = $this->f->fahrt($version, ['A', 'B'], ['22:49:00', '23:20:00']);
        $nachts = $this->f->fahrt($version, ['B', 'A'], ['00:19:00', '00:50:00']);

        $this->verknuepfe($abends, $nachts);
        $this->setzeKurs($abends, ['number' => '01'])->assertOk();

        $kurse = $this->withToken($this->token())
            ->getJson("/api/v1/admin/courses?period={$version->period_id}&day_type=mo_fr")
            ->assertOk()->json('data');

        $this->assertSame('22:49:00', $kurse[0]['first_departure']);
        $this->assertSame('00:50:00', $kurse[0]['last_arrival']);
    }

    public function test_courses_are_sorted_naturally_by_number(): void
    {
        $version = $this->version();
        $periode = $version->period_id;

        foreach (['10', '2', '1'] as $nummer) {
            Course::query()->create(['period_id' => $periode, 'day_type' => 'mo_fr', 'number' => $nummer]);
        }

        $kurse = $this->withToken($this->token())
            ->getJson("/api/v1/admin/courses?period={$periode}&day_type=mo_fr")
            ->assertOk()->json('data');

        $this->assertSame(['1', '2', '10'], array_column($kurse, 'number'));
    }

    public function test_timetable_shows_the_course_per_trip(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['A', 'B'], ['06:14:00', '06:48:00']);

        $this->setzeKurs($fahrt, ['number' => '03'])->assertOk();

        $daten = $this->withToken($this->token())
            ->getJson("/api/v1/admin/line-versions/{$version->id}/timetable")
            ->assertOk()->json('data');

        $spalte = $daten['directions'][0]['trips'][0];

        $this->assertSame('03', $spalte['course']['number']);
        $this->assertSame('1/03', $spalte['course']['display'], 'Linien-Praefix ist Anzeige');
    }

    public function test_timetable_shows_null_for_trips_without_a_course(): void
    {
        $version = $this->version();
        $this->f->fahrt($version, ['A', 'B'], ['06:14:00', '06:48:00']);

        $daten = $this->withToken($this->token())
            ->getJson("/api/v1/admin/line-versions/{$version->id}/timetable")
            ->assertOk()->json('data');

        $this->assertNull($daten['directions'][0]['trips'][0]['course']);
    }
}
