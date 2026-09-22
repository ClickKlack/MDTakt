<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Models\ConsolidatedTrip;
use App\Models\Depot;
use App\Models\LineVersion;
use App\Models\SchedulePeriod;
use App\Models\TripLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Die Umläufe einer Linie im Ganzen: Ketten, Risse und Fahrten ohne Kurs.
 */
final class CourseOverviewTest extends TestCase
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

    private function version(string $linie = '1'): LineVersion
    {
        $version = $this->f->version($linie, FahrplanTyp::MoFrNormal, 1, $this->periode);
        $this->f->gueltigkeit($version);

        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    private function hole(string $linie): array
    {
        return $this->withToken($this->token())
            ->getJson("/api/v1/admin/lines/{$linie}/courses?period={$this->periode->id}&day_type=mo_fr")
            ->assertOk()
            ->json('data');
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
        $this->getJson('/api/v1/admin/lines/1/courses')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_missing_filters_are_rejected(): void
    {
        $this->withToken($this->token())
            ->getJson('/api/v1/admin/lines/1/courses')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    // ---------------------------------------------------------------- Betriebshof (KURSE §3.2)

    /**
     * Aus- und Einrückhof stehen am Umlauf — und sie sind **nicht** zwangsläufig derselbe.
     * Ein Fahrzeug rückt morgens aus Nord aus und abends in Westerhüsen ein, wenn der Umlauf
     * es dorthin trägt.
     */
    public function test_a_course_reports_its_outbound_and_inbound_depot(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['Nord', 'B'], ['04:30:00', '05:00:00']);
        $b = $this->f->fahrt($version, ['B', 'Westerhüsen'], ['05:05:00', '05:35:00']);

        $this->verknuepfe($a, $b);
        $this->setzeKurs($a, '03');

        $nord = Depot::factory()->create(['name' => 'Nord-Hof', 'short_name' => 'Nord']);
        $west = Depot::factory()->create(['name' => 'West-Hof', 'short_name' => 'West']);

        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'start', 'to_trip_id' => $a->id, 'depot_id' => $nord->id,
        ])->assertCreated();

        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'end', 'from_trip_id' => $b->id, 'depot_id' => $west->id,
        ])->assertCreated();

        $kurs = $this->hole('1')['courses'][0];

        $this->assertTrue($kurs['terminal_out']['marked']);
        $this->assertSame('Nord', $kurs['terminal_out']['depot']['display']);
        $this->assertTrue($kurs['terminal_in']['marked']);
        $this->assertSame('West', $kurs['terminal_in']['depot']['display']);
    }

    /**
     * Drei Zustände, die auseinanderzuhalten sind: keine Marke (die Lücke), Marke ohne Hof
     * (festgehalten, Hof offen) und Marke mit Hof. Fielen die ersten beiden zusammen, wäre
     * eine gepflegte Betriebsfahrt von einem Pflegerückstand nicht zu unterscheiden.
     */
    public function test_a_mark_without_a_depot_is_not_the_same_as_no_mark(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['Nord', 'B'], ['04:30:00', '05:00:00']);
        $b = $this->f->fahrt($version, ['B', 'Westerhüsen'], ['05:05:00', '05:35:00']);

        $this->verknuepfe($a, $b);
        $this->setzeKurs($a, '03');

        // Nur das Ausrücken markiert, und zwar ohne Hof.
        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'start', 'to_trip_id' => $a->id,
        ])->assertCreated();

        $kurs = $this->hole('1')['courses'][0];

        $this->assertTrue($kurs['terminal_out']['marked'], 'Markiert, aber ohne Hof.');
        $this->assertNull($kurs['terminal_out']['depot']);

        $this->assertFalse($kurs['terminal_in']['marked'], 'Gar nicht markiert — das ist die Lücke.');
        $this->assertNull($kurs['terminal_in']['depot']);
    }

    public function test_a_chain_appears_in_order_with_its_trips(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);

        $this->verknuepfe($a, $b);
        $this->setzeKurs($a, '03');

        $daten = $this->hole('1');

        $this->assertCount(1, $daten['courses']);
        $this->assertSame('03', $daten['courses'][0]['number']);
        $this->assertSame([$a->id, $b->id], array_column($daten['courses'][0]['trips'], 'id'));
        $this->assertSame('06:00:00', $daten['courses'][0]['first_departure']);
        $this->assertSame('07:05:00', $daten['courses'][0]['last_arrival']);
    }

    /**
     * Abstand und Anschluss sind zwei verschiedene Dinge: Ein großer Abstand **mit** Anschluss
     * ist eine lange Wende, ein Abstand **ohne** Anschluss eine gerissene Kette.
     */
    public function test_gap_and_link_are_reported_separately(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);

        $this->verknuepfe($a, $b);
        $this->setzeKurs($a, '03');

        $trips = $this->hole('1')['courses'][0]['trips'];

        $this->assertNull($trips[0]['gap_before_seconds'], 'Die erste Fahrt hat keine Vorfahrt');
        $this->assertFalse($trips[0]['linked_to_previous']);

        // 06:30 -> 06:35 sind fuenf Minuten.
        $this->assertSame(300, $trips[1]['gap_before_seconds']);
        $this->assertTrue($trips[1]['linked_to_previous']);
        $this->assertSame(0, $this->hole('1')['courses'][0]['breaks']);
    }

    /**
     * Ein Umlauf muss keine durchgehende Kette sein: Löst man einen Anschluss, bleibt der Kurs
     * an beiden Teilen hängen — und die Übersicht muss den Riss zeigen, statt ihn zu glätten.
     */
    public function test_a_broken_chain_is_counted_as_a_break(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);

        $this->verknuepfe($a, $b);
        $this->setzeKurs($a, '03');

        $linkId = TripLink::query()->sole()->id;
        $this->withToken($this->token())->deleteJson("/api/v1/admin/trip-links/{$linkId}")->assertNoContent();

        $kurs = $this->hole('1')['courses'][0];

        $this->assertSame(2, $kurs['trip_count'], 'Der Kurs haengt weiter an beiden Teilen');
        $this->assertSame(1, $kurs['breaks']);
        $this->assertFalse($kurs['trips'][1]['linked_to_previous']);
    }

    /** Ein Umlauf über mehrere Linien erscheint bei jeder von ihnen — mit allen seinen Fahrten. */
    public function test_a_course_across_lines_appears_under_both(): void
    {
        $eins = $this->version('1');
        $dreizehn = $this->version('13');

        $aufEins = $this->f->fahrt($eins, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);
        $aufDreizehn = $this->f->fahrt($dreizehn, ['Sudenburg', 'Westerhüsen'], ['06:52:00', '07:24:00']);

        $this->verknuepfe($aufEins, $aufDreizehn);
        $this->setzeKurs($aufEins, '03');

        foreach (['1', '13'] as $linie) {
            $daten = $this->hole($linie);

            $this->assertCount(1, $daten['courses'], "Linie {$linie} findet den Umlauf nicht");
            $this->assertSame(['1', '13'], $daten['courses'][0]['lines']);
            $this->assertSame(2, $daten['courses'][0]['trip_count'], 'Auch die Fahrten der anderen Linie');
        }
    }

    public function test_trips_without_a_course_are_listed_separately(): void
    {
        $version = $this->version();
        $mitKurs = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $ohneKurs = $this->f->fahrt($version, ['A', 'B'], ['08:00:00', '08:30:00']);

        $this->setzeKurs($mitKurs, '03');

        $daten = $this->hole('1');

        $this->assertSame([$ohneKurs->id], array_column($daten['unassigned'], 'id'));
        $this->assertSame(1, $daten['summary']['unassigned_trips']);
        $this->assertSame(1, $daten['summary']['assigned_trips']);
    }

    /**
     * Die Reihenfolge folgt dem Betriebstag: Auf der N1 beginnt der Umlauf abends und endet
     * am Morgen darauf.
     */
    public function test_the_chain_follows_the_operating_day(): void
    {
        $version = $this->version('N1');
        $abends = $this->f->fahrt($version, ['A', 'B'], ['22:49:00', '23:20:00']);
        $nachts = $this->f->fahrt($version, ['B', 'A'], ['00:19:00', '00:50:00']);

        $this->verknuepfe($abends, $nachts);
        $this->setzeKurs($abends, '01');

        $kurs = $this->hole('N1')['courses'][0];

        $this->assertSame([$abends->id, $nachts->id], array_column($kurs['trips'], 'id'));
        $this->assertSame('22:49:00', $kurs['first_departure']);
        $this->assertSame('00:50:00', $kurs['last_arrival']);
        // 23:20 -> 00:19 sind 59 Minuten, kein Ruecksprung.
        $this->assertSame(3540, $kurs['trips'][1]['gap_before_seconds']);
    }

    public function test_courses_are_sorted_naturally(): void
    {
        $version = $this->version();

        foreach (['10', '2', '1'] as $i => $nummer) {
            $fahrt = $this->f->fahrt($version, ['A', 'B'], [sprintf('%02d:00:00', 6 + $i), sprintf('%02d:30:00', 6 + $i)]);
            $this->setzeKurs($fahrt, $nummer);
        }

        $this->assertSame(['1', '2', '10'], array_column($this->hole('1')['courses'], 'number'));
    }

    public function test_a_line_without_courses_yields_empty_lists(): void
    {
        $this->version();

        $daten = $this->hole('1');

        $this->assertSame([], $daten['courses']);
        $this->assertSame([], $daten['unassigned']);
        $this->assertSame(0, $daten['summary']['courses']);
    }

    public function test_the_chain_view_carries_no_stop_sequences(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B', 'C'], ['06:00:00', '06:15:00', '06:30:00']);
        $this->setzeKurs($a, '03');

        // Eine Linie mit acht Umläufen brächte sonst mehrere tausend Haltezeilen mit, die die
        // Kettenansicht gar nicht braucht. Wer sie will, nimmt die Tabelle.
        $this->assertArrayNotHasKey('stops', $this->hole('1')['courses'][0]['trips'][0]);
    }
}
