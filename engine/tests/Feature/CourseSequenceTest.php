<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Enums\TripLinkKind;
use App\Models\ConsolidatedTrip;
use App\Models\Course;
use App\Models\CourseTrip;
use App\Models\LineVersion;
use App\Models\TripLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Kursnummern über einen Spaltenbereich fortschreiben und wieder entfernen.
 *
 * Zwei Dinge sind hier entscheidend und deshalb mehrfach festgenagelt: Führende Nullen sind
 * bedeutungstragend, und die Kette zieht mit — ein Lauf fasst mehr Fahrten an, als markiert sind.
 */
final class CourseSequenceTest extends TestCase
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

    private function version(string $linie = '6'): LineVersion
    {
        $version = $this->f->version($linie, FahrplanTyp::MoFrNormal, 1, $this->f->periode());
        $this->f->gueltigkeit($version);

        return $version;
    }

    /** Eine Fahrt der Hinrichtung: Olvenstedt → Sudenburg. */
    private function hin(LineVersion $version, string $ab, string $an): ConsolidatedTrip
    {
        return $this->f->fahrt($version, ['Olvenstedt', 'Sudenburg'], [$ab, $an]);
    }

    /** Eine Fahrt der Gegenrichtung: Sudenburg → Olvenstedt. */
    private function zurueck(LineVersion $version, string $ab, string $an): ConsolidatedTrip
    {
        return $this->f->fahrt($version, ['Sudenburg', 'Olvenstedt'], [$ab, $an]);
    }

    private function verknuepfe(ConsolidatedTrip $von, ConsolidatedTrip $nach): void
    {
        TripLink::query()->create([
            'from_trip_id' => $von->id,
            'to_trip_id' => $nach->id,
            'stop_id' => $von->last_stop_id,
            'kind' => TripLinkKind::Link,
        ]);
    }

    private function kursAn(ConsolidatedTrip $fahrt, string $nummer): Course
    {
        $kurs = Course::query()->create([
            'period_id' => $this->f->periode()->id,
            'day_type' => FahrplanTyp::MoFrNormal->value,
            'number' => $nummer,
        ]);

        CourseTrip::query()->create(['course_id' => $kurs->id, 'consolidated_trip_id' => $fahrt->id]);

        return $kurs;
    }

    /**
     * @param  array<string, mixed>  $zusatz
     * @return array<string, mixed>
     */
    private function rumpf(ConsolidatedTrip $von, ConsolidatedTrip $bis, array $zusatz = []): array
    {
        return ['from_trip_id' => $von->id, 'to_trip_id' => $bis->id] + $zusatz;
    }

    /**
     * @param  array<string, mixed>  $rumpf
     * @return array<string, mixed>
     */
    private function vorschau(LineVersion $version, array $rumpf): array
    {
        return $this->withToken($this->token())
            ->getJson("/api/v1/admin/line-versions/{$version->id}/course-sequence?".http_build_query($rumpf))
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $rumpf
     * @return array<string, mixed>
     */
    private function anwenden(LineVersion $version, array $rumpf): array
    {
        return $this->withToken($this->token())
            ->postJson("/api/v1/admin/line-versions/{$version->id}/course-sequence", $rumpf)
            ->assertOk()
            ->json('data');
    }

    private function nummerVon(ConsolidatedTrip $fahrt): ?string
    {
        return Course::query()
            ->join('course_trips', 'courses.id', '=', 'course_trips.course_id')
            ->where('course_trips.consolidated_trip_id', $fahrt->id)
            ->value('courses.number');
    }

    // ---------------------------------------------------------------- Zugang und Vorschau

    public function test_requires_authentication(): void
    {
        $version = $this->version();
        $a = $this->hin($version, '05:04:00', '05:40:00');

        $this->getJson("/api/v1/admin/line-versions/{$version->id}/course-sequence?from_trip_id={$a->id}&to_trip_id={$a->id}&pattern=1-2")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);

        $this->postJson("/api/v1/admin/line-versions/{$version->id}/course-sequence", $this->rumpf($a, $a, ['pattern' => '1-2']))
            ->assertStatus(401);

        $this->assertDatabaseCount('course_trips', 0);
    }

    public function test_preview_changes_nothing(): void
    {
        $version = $this->version();
        $a = $this->hin($version, '05:04:00', '05:40:00');
        $b = $this->hin($version, '05:14:00', '05:50:00');

        $daten = $this->vorschau($version, $this->rumpf($a, $b, ['pattern' => '1-2']));

        $this->assertFalse($daten['summary']['applied']);
        $this->assertSame(2, $daten['summary']['planned']);
        $this->assertSame(0, $daten['summary']['written']);
        $this->assertDatabaseCount('course_trips', 0);
        $this->assertDatabaseCount('courses', 0);
    }

    // ---------------------------------------------------------------- Das Muster

    public function test_numbers_run_cyclically_over_the_marked_columns(): void
    {
        $version = $this->version();

        $spalten = [
            $this->hin($version, '05:04:00', '05:40:00'),
            $this->hin($version, '05:14:00', '05:50:00'),
            $this->hin($version, '05:24:00', '06:00:00'),
            $this->hin($version, '05:34:00', '06:10:00'),
        ];

        $daten = $this->anwenden($version, $this->rumpf($spalten[0], $spalten[3], ['pattern' => '1-2']));

        $this->assertSame(4, $daten['summary']['written']);
        $this->assertSame(['1', '2'], $daten['pattern']['numbers']);

        foreach (['1', '2', '1', '2'] as $i => $erwartet) {
            $this->assertSame($erwartet, $this->nummerVon($spalten[$i]), "Spalte {$i} trägt die falsche Nummer.");
        }
    }

    public function test_leading_zeros_are_preserved(): void
    {
        $version = $this->version();

        $a = $this->hin($version, '05:04:00', '05:40:00');
        $b = $this->hin($version, '05:14:00', '05:50:00');

        $this->anwenden($version, $this->rumpf($a, $b, ['pattern' => '01-02']));

        // „03" und „3" sind für die Datenbank zwei verschiedene Kurse — der Bestand schreibt
        // gepolstert, und geraten wird hier nichts.
        $this->assertSame('01', $this->nummerVon($a));
        $this->assertSame('02', $this->nummerVon($b));
        $this->assertDatabaseHas('courses', ['number' => '01']);
        $this->assertDatabaseMissing('courses', ['number' => '1']);
    }

    public function test_the_sequence_starts_at_the_marked_column(): void
    {
        $version = $this->version();

        $spalten = [
            $this->hin($version, '05:04:00', '05:40:00'),
            $this->hin($version, '05:14:00', '05:50:00'),
            $this->hin($version, '05:24:00', '06:00:00'),
        ];

        // Ab der zweiten Spalte: Die Folge beginnt dort, wo der Pflegende sie beginnen lässt.
        $this->anwenden($version, $this->rumpf($spalten[1], $spalten[2], ['pattern' => '5-6']));

        $this->assertNull($this->nummerVon($spalten[0]));
        $this->assertSame('5', $this->nummerVon($spalten[1]));
        $this->assertSame('6', $this->nummerVon($spalten[2]));
    }

    public function test_columns_of_a_night_line_follow_the_operating_day(): void
    {
        $nacht = $this->version('N1');

        $spaet = $this->f->fahrt($nacht, ['Olvenstedt', 'Sudenburg'], ['22:49:00', '23:20:00']);
        $frueh = $this->f->fahrt($nacht, ['Olvenstedt', 'Sudenburg'], ['00:19:00', '00:50:00']);

        $daten = $this->anwenden($nacht, $this->rumpf($spaet, $frueh, ['pattern' => '1-2']));

        // Auf dem Betriebstag fährt 22:49 vor 00:19 — nach der Uhr wäre es umgekehrt, und die
        // halbe Nacht stünde am Tabellenanfang.
        $this->assertSame(0, $daten['range']['from_index']);
        $this->assertSame('1', $this->nummerVon($spaet));
        $this->assertSame('2', $this->nummerVon($frueh));
    }

    public function test_range_marked_backwards_is_swapped(): void
    {
        $version = $this->version();

        $a = $this->hin($version, '05:04:00', '05:40:00');
        $b = $this->hin($version, '05:14:00', '05:50:00');

        $daten = $this->anwenden($version, $this->rumpf($b, $a, ['pattern' => '1-2']));

        $this->assertSame(2, $daten['summary']['written']);
        $this->assertSame('1', $this->nummerVon($a));
    }

    // ---------------------------------------------------------------- Die Kette zieht mit

    public function test_assignment_writes_through_the_chain(): void
    {
        $version = $this->version();

        $hin = $this->hin($version, '05:04:00', '05:40:00');
        $rueck = $this->zurueck($version, '05:48:00', '06:24:00');
        $this->verknuepfe($hin, $rueck);

        $daten = $this->anwenden($version, $this->rumpf($hin, $hin, ['pattern' => '1']));

        $this->assertSame(2, $daten['assignments'][0]['chain_trip_count']);
        $this->assertSame('1', $this->nummerVon($hin));
        $this->assertSame('1', $this->nummerVon($rueck), 'Der Kurs gilt für die ganze Kette.');
    }

    public function test_chain_trips_outside_the_range_are_shown(): void
    {
        $version = $this->version();

        $hin = $this->hin($version, '05:04:00', '05:40:00');
        $rueck = $this->zurueck($version, '05:48:00', '06:24:00');
        $this->verknuepfe($hin, $rueck);

        $daten = $this->vorschau($version, $this->rumpf($hin, $hin, ['pattern' => '1']));

        $this->assertSame(1, $daten['summary']['columns']);
        $this->assertSame(2, $daten['summary']['trips_affected'], 'Mehr Fahrten als Spalten — genau das muss sichtbar sein.');
        $this->assertSame(1, $daten['summary']['outside_range']);
        $this->assertSame($rueck->id, $daten['assignments'][0]['outside_range'][0]['id']);
    }

    // ---------------------------------------------------------------- Konflikte und Bestand

    public function test_trip_with_an_existing_course_is_skipped(): void
    {
        $version = $this->version();

        $a = $this->hin($version, '05:04:00', '05:40:00');
        $b = $this->hin($version, '05:14:00', '05:50:00');
        $this->kursAn($a, '12');

        $daten = $this->anwenden($version, $this->rumpf($a, $b, ['pattern' => '1-2']));

        $this->assertSame(1, $daten['summary']['skipped']);
        $this->assertSame('already_assigned', $daten['skipped'][0]['reason_code']);
        $this->assertSame('12', $this->nummerVon($a), 'Die bestehende Nummer bleibt stehen.');
        $this->assertSame('2', $this->nummerVon($b));
    }

    public function test_an_existing_course_on_the_chain_protects_the_whole_chain(): void
    {
        $version = $this->version();

        $hin = $this->hin($version, '05:04:00', '05:40:00');
        $rueck = $this->zurueck($version, '05:48:00', '06:24:00');
        $this->verknuepfe($hin, $rueck);

        // Die Nummer hängt an der Gegenrichtung, die gar nicht markiert ist. Auf Fahrtebene
        // geprüft überschriebe der Lauf sie still — `assign()` schreibt die ganze Kette.
        $this->kursAn($rueck, '12');

        $daten = $this->anwenden($version, $this->rumpf($hin, $hin, ['pattern' => '1']));

        $this->assertSame(1, $daten['summary']['skipped']);
        $this->assertSame('already_assigned', $daten['skipped'][0]['reason_code']);
        $this->assertSame('12', $this->nummerVon($rueck));
        $this->assertNull($this->nummerVon($hin));
    }

    public function test_identical_number_counts_as_unchanged(): void
    {
        $version = $this->version();

        $a = $this->hin($version, '05:04:00', '05:40:00');
        $this->kursAn($a, '1');

        $daten = $this->anwenden($version, $this->rumpf($a, $a, ['pattern' => '1']));

        $this->assertSame(1, $daten['summary']['unchanged']);
        $this->assertSame(0, $daten['summary']['skipped']);
        $this->assertSame(0, $daten['summary']['written']);
    }

    public function test_two_trips_of_one_chain_with_different_numbers_conflict(): void
    {
        $version = $this->version();

        $a = $this->hin($version, '05:04:00', '05:40:00');
        $b = $this->hin($version, '05:14:00', '05:50:00');

        // Beide Spalten gehören derselben Kette — über eine Fahrt der Gegenrichtung verbunden.
        $mitte = $this->zurueck($version, '05:44:00', '06:10:00');
        $this->verknuepfe($a, $mitte);
        $this->verknuepfe($mitte, $b);

        $daten = $this->anwenden($version, $this->rumpf($a, $b, ['pattern' => '1-2']));

        $this->assertSame(1, $daten['summary']['conflicts']);
        $this->assertSame(['1', '2'], $daten['conflicts'][0]['numbers']);
        $this->assertSame(0, $daten['summary']['written']);
        // Bei Widerspruch wird gar nichts geschrieben — sequenziell ausgeführt gewänne sonst
        // willkürlich die letzte Nummer.
        $this->assertDatabaseCount('course_trips', 0);
    }

    public function test_two_trips_of_one_chain_with_the_same_number_are_no_conflict(): void
    {
        $version = $this->version();

        $a = $this->hin($version, '05:04:00', '05:40:00');
        $b = $this->hin($version, '05:14:00', '05:50:00');

        $mitte = $this->zurueck($version, '05:44:00', '06:10:00');
        $this->verknuepfe($a, $mitte);
        $this->verknuepfe($mitte, $b);

        // Zyklisches Muster der Länge 1: Beide Spalten sollen „1" bekommen. Das Fahrzeug kommt
        // eben wieder — das ist der Normalfall, kein Widerspruch.
        $daten = $this->anwenden($version, $this->rumpf($a, $b, ['pattern' => '1']));

        $this->assertSame(0, $daten['summary']['conflicts']);
        $this->assertSame(1, $daten['summary']['written']);
        $this->assertSame('1', $this->nummerVon($a));
        $this->assertSame('1', $this->nummerVon($b));
    }

    public function test_apply_is_idempotent(): void
    {
        $version = $this->version();

        $a = $this->hin($version, '05:04:00', '05:40:00');
        $b = $this->hin($version, '05:14:00', '05:50:00');

        $this->anwenden($version, $this->rumpf($a, $b, ['pattern' => '1-2']));
        $zweiter = $this->anwenden($version, $this->rumpf($a, $b, ['pattern' => '1-2']));

        $this->assertSame(0, $zweiter['summary']['written']);
        $this->assertSame(2, $zweiter['summary']['unchanged']);
        $this->assertDatabaseCount('course_trips', 2);
    }

    // ---------------------------------------------------------------- Abgewiesenes

    public function test_marked_columns_must_belong_to_the_same_direction(): void
    {
        $version = $this->version();

        $hin = $this->hin($version, '05:04:00', '05:40:00');
        $rueck = $this->zurueck($version, '05:48:00', '06:24:00');

        $this->withToken($this->token())
            ->getJson("/api/v1/admin/line-versions/{$version->id}/course-sequence?".http_build_query(
                $this->rumpf($hin, $rueck, ['pattern' => '1-2'])
            ))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);

        $this->withToken($this->token())
            ->postJson("/api/v1/admin/line-versions/{$version->id}/course-sequence", $this->rumpf($hin, $rueck, ['pattern' => '1-2']))
            ->assertStatus(422);

        $this->assertDatabaseCount('course_trips', 0);
    }

    public function test_trip_from_another_version_is_rejected(): void
    {
        $version = $this->version();
        $andere = $this->version('8');

        $a = $this->hin($version, '05:04:00', '05:40:00');
        $fremd = $this->hin($andere, '05:14:00', '05:50:00');

        $this->withToken($this->token())
            ->postJson("/api/v1/admin/line-versions/{$version->id}/course-sequence", $this->rumpf($a, $fremd, ['pattern' => '1-2']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_a_broken_pattern_is_rejected_with_422(): void
    {
        $version = $this->version();
        $a = $this->hin($version, '05:04:00', '05:40:00');

        foreach (['A-C', '1-', '', '1-99999999'] as $muster) {
            $this->withToken($this->token())
                ->postJson("/api/v1/admin/line-versions/{$version->id}/course-sequence", $this->rumpf($a, $a, ['pattern' => $muster]))
                ->assertStatus(422)
                ->assertJsonPath('error.code', 422);
        }

        $this->assertDatabaseCount('courses', 0);
    }

    // ---------------------------------------------------------------- Entfernen

    public function test_clear_preview_changes_nothing(): void
    {
        $version = $this->version();
        $a = $this->hin($version, '05:04:00', '05:40:00');
        $this->kursAn($a, '1');

        $daten = $this->vorschau($version, $this->rumpf($a, $a, ['action' => 'clear']));

        $this->assertFalse($daten['summary']['applied']);
        $this->assertSame(1, $daten['summary']['planned']);
        $this->assertNull($daten['pattern']);
        $this->assertDatabaseCount('course_trips', 1);
    }

    public function test_clear_removes_the_courses_of_the_marked_columns(): void
    {
        $version = $this->version();

        $a = $this->hin($version, '05:04:00', '05:40:00');
        $b = $this->hin($version, '05:14:00', '05:50:00');
        $c = $this->hin($version, '05:24:00', '06:00:00');

        $this->kursAn($a, '1');
        $this->kursAn($b, '2');
        $this->kursAn($c, '3');

        $daten = $this->anwenden($version, $this->rumpf($a, $b, ['action' => 'clear']));

        $this->assertSame(2, $daten['summary']['removed']);
        $this->assertNull($this->nummerVon($a));
        $this->assertNull($this->nummerVon($b));
        $this->assertSame('3', $this->nummerVon($c), 'Spalten außerhalb des Bereichs bleiben.');
    }

    public function test_clear_works_through_the_whole_chain(): void
    {
        $version = $this->version();

        $hin = $this->hin($version, '05:04:00', '05:40:00');
        $rueck = $this->zurueck($version, '05:48:00', '06:24:00');
        $this->verknuepfe($hin, $rueck);
        $this->kursAn($hin, '1');
        CourseTrip::query()->create([
            'course_id' => Course::query()->where('number', '1')->value('id'),
            'consolidated_trip_id' => $rueck->id,
        ]);

        $daten = $this->anwenden($version, $this->rumpf($hin, $hin, ['action' => 'clear']));

        // Die Gegenrichtung ist nicht markiert und verliert ihren Kurs trotzdem — sie steht
        // deshalb vorher in `outside_range`.
        $this->assertSame(1, $daten['summary']['outside_range']);
        $this->assertSame(2, $daten['summary']['trips_affected']);
        $this->assertNull($this->nummerVon($hin));
        $this->assertNull($this->nummerVon($rueck));
    }

    public function test_clear_keeps_the_links(): void
    {
        $version = $this->version();

        $hin = $this->hin($version, '05:04:00', '05:40:00');
        $rueck = $this->zurueck($version, '05:48:00', '06:24:00');
        $this->verknuepfe($hin, $rueck);
        $this->kursAn($hin, '1');

        $this->anwenden($version, $this->rumpf($hin, $hin, ['action' => 'clear']));

        // `clear` nimmt das Etikett ab, nicht die Kette — der Umlauf bleibt verkettet und kann
        // sofort neu benummert werden.
        $this->assertDatabaseCount('trip_links', 1);
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $hin->id, 'to_trip_id' => $rueck->id]);
    }

    public function test_clear_skips_a_column_without_a_course(): void
    {
        $version = $this->version();
        $a = $this->hin($version, '05:04:00', '05:40:00');

        $daten = $this->anwenden($version, $this->rumpf($a, $a, ['action' => 'clear']));

        $this->assertSame(0, $daten['summary']['removed']);
        $this->assertSame('no_course', $daten['skipped'][0]['reason_code']);
    }

    public function test_clear_reports_courses_left_without_trips(): void
    {
        $version = $this->version();
        $a = $this->hin($version, '05:04:00', '05:40:00');
        $this->kursAn($a, '1');

        $daten = $this->vorschau($version, $this->rumpf($a, $a, ['action' => 'clear']));

        $this->assertSame('course_empty_after', $daten['warnings'][0]['code']);

        $this->anwenden($version, $this->rumpf($a, $a, ['action' => 'clear']));

        // Der Umlauf bleibt bestehen: Seine Nummer ist nicht verbraucht, und gelöscht wird er
        // nur ausdrücklich über den Kurs-Endpunkt.
        $this->assertDatabaseHas('courses', ['number' => '1']);
        $this->assertDatabaseCount('course_trips', 0);
    }

    public function test_clear_needs_no_pattern_and_is_idempotent(): void
    {
        $version = $this->version();
        $a = $this->hin($version, '05:04:00', '05:40:00');
        $this->kursAn($a, '1');

        $this->anwenden($version, $this->rumpf($a, $a, ['action' => 'clear']));
        $zweiter = $this->anwenden($version, $this->rumpf($a, $a, ['action' => 'clear']));

        $this->assertSame(0, $zweiter['summary']['removed']);
        $this->assertSame('no_course', $zweiter['skipped'][0]['reason_code']);
    }
}
