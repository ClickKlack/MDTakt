<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Models\ConsolidatedTrip;
use App\Models\LineVersion;
use App\Models\SchedulePeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Die Umläufe einer Linie als klassische Tabelle: Halte als Zeilen, ein Kurs je Spalte.
 *
 * Zwei Zusicherungen stehen hier im Mittelpunkt: Die Spalten laufen **nebeneinander** auf einer
 * gemeinsamen Achse, und in der Zelle steht die Abfahrt — außer am letzten Halt einer Fahrt.
 */
final class CourseGridTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function hole(string $linie = '1'): array
    {
        return $this->withToken($this->token())
            ->getJson("/api/v1/admin/lines/{$linie}/course-grid?period={$this->periode->id}&day_type=mo_fr")
            ->assertOk()
            ->json('data');
    }

    /**
     * Die Zeiten einer Spalte in Zeilenreihenfolge; `null` bleibt `null`.
     *
     * @param  array<string, mixed>  $spalte
     * @return array<int, string|null>
     */
    private function zeiten(array $spalte): array
    {
        return array_map(static fn (?array $z): ?string => $z['time'] ?? null, $spalte['cells']);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/lines/1/course-grid')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_missing_filters_are_rejected(): void
    {
        $this->withToken($this->token())
            ->getJson('/api/v1/admin/lines/1/course-grid')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_a_line_without_courses_yields_an_empty_grid(): void
    {
        $this->version();

        $daten = $this->hole();

        $this->assertSame([], $daten['rows']);
        $this->assertSame([], $daten['courses']);
        $this->assertFalse($daten['alignment_warning']);
    }

    public function test_stops_become_rows_and_a_course_becomes_a_column(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B', 'C'], ['06:00:00', '06:15:00', '06:30:00']);
        $this->setzeKurs($a, '03');

        $daten = $this->hole();

        $this->assertSame(['A', 'B', 'C'], array_column($daten['rows'], 'stop_name'));
        $this->assertCount(1, $daten['courses']);
        $this->assertSame('03', $daten['courses'][0]['number']);
    }

    /**
     * In der Zelle steht die **Abfahrt** — außer am letzten Halt einer Fahrt, dort die Ankunft.
     * So liest sich eine Spalte wie ein Fahrplan.
     */
    public function test_a_cell_carries_the_departure_and_the_last_one_the_arrival(): void
    {
        $version = $this->version();
        // Ankunft und Abfahrt bewusst verschieden, sonst bewiese der Test nichts.
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $a->stopTimes()->where('stop_sequence', 2)->update(['departure_time' => '06:32:00']);
        $this->setzeKurs($a, '03');

        $zellen = $this->hole()['courses'][0]['cells'];

        $this->assertSame('departure', $zellen[0]['kind']);
        $this->assertSame('06:00:00', $zellen[0]['time']);

        $this->assertSame('arrival', $zellen[1]['kind']);
        $this->assertSame('06:30:00', $zellen[1]['time'], 'Am letzten Halt zählt die Ankunft, nicht die Abfahrt.');
    }

    /**
     * Die Endstelle trägt zwei Zeiten in zwei Zeilen: die Ankunft, mit der eine Fahrt endet, und
     * die Abfahrt, mit der die nächste beginnt. Beide sind hervorzuheben, und dazwischen gehört
     * die Trennlinie — dafür muss die Zelle sagen, dass dort eine Fahrt beginnt.
     */
    public function test_a_cell_says_when_a_trip_starts(): void
    {
        $version = $this->version();

        $hin = $this->f->fahrt($version, ['A', 'B', 'C'], ['06:00:00', '06:15:00', '06:30:00']);
        $rueck = $this->f->fahrt($version, ['C', 'B', 'A'], ['06:40:00', '06:55:00', '07:10:00']);

        $this->verknuepfe($hin, $rueck);
        $this->setzeKurs($hin, '01');

        $zellen = $this->hole()['courses'][0]['cells'];

        $this->assertSame(
            [true, false, false, true, false, false],
            array_map(static fn (?array $z): ?bool => $z['starts_trip'] ?? null, $zellen),
        );

        // Das Paar an der Endstelle: Ankunft der ersten Fahrt, Abfahrt der zweiten.
        $this->assertSame('arrival', $zellen[2]['kind']);
        $this->assertTrue($zellen[3]['starts_trip']);
    }

    /**
     * Der Kern der Umstellung: Zwei Umläufe stehen **nebeneinander**, nicht untereinander.
     * Fahren sie dieselbe Folge versetzt, laufen ihre Spalten Zeile für Zeile parallel.
     */
    public function test_two_courses_share_the_axis_side_by_side(): void
    {
        $version = $this->version();

        $a = $this->f->fahrt($version, ['A', 'B', 'C'], ['06:00:00', '06:15:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['A', 'B', 'C'], ['06:10:00', '06:25:00', '06:40:00']);

        $this->setzeKurs($a, '01');
        $this->setzeKurs($b, '02');

        $daten = $this->hole();

        $this->assertCount(3, $daten['rows'], 'Der gemeinsame Laufweg ergibt drei Zeilen, nicht sechs.');
        $this->assertSame(['01', '02'], array_column($daten['courses'], 'number'));

        $this->assertSame(['06:00:00', '06:15:00', '06:30:00'], $this->zeiten($daten['courses'][0]));
        $this->assertSame(['06:10:00', '06:25:00', '06:40:00'], $this->zeiten($daten['courses'][1]));
    }

    /**
     * Die Achse folgt der Kette: Nach der Endstelle kommen die Halte der Rückfahrt, dieselben
     * Namen in umgekehrter Folge. Gespiegelt wird nichts — jede Zeit läuft von oben nach unten.
     */
    public function test_the_axis_follows_the_chain_across_the_turnaround(): void
    {
        $version = $this->version();

        $hin = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $rueck = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);

        $this->verknuepfe($hin, $rueck);
        $this->setzeKurs($hin, '01');

        $daten = $this->hole();

        $this->assertSame(['A', 'B', 'B', 'A'], array_column($daten['rows'], 'stop_name'));
        $this->assertSame(['06:00:00', '06:30:00', '06:35:00', '07:05:00'], $this->zeiten($daten['courses'][0]));

        // Die zweite Berührung derselben Haltestelle ist die nächste Runde, keine Doppelung.
        $this->assertSame([0, 0, 1, 1], array_column($daten['rows'], 'repeat_index'));
    }

    /**
     * Der Kern der Verschiebung: Beginnt ein Umlauf eine Runde früher, rutscht seine Spalte um
     * einen **ganzen Umlauf**, bis die Zeile quer gelesen den Takt ergibt.
     *
     * Kurs 02 fährt hier 30 Minuten vor Kurs 01. Ohne Verschiebung stünde in der ersten Zeile
     * 06:00 neben 05:30 — die Zeile liefe rückwärts. Verschoben steht dort 06:00 neben 06:30:
     * zwei aufeinanderfolgende Abfahrten desselben Takts.
     */
    public function test_a_column_slides_by_whole_rounds_until_the_row_reads_forward(): void
    {
        $version = $this->version();

        // Runde = 60 Minuten, Takt = 30 Minuten. Je zwei Runden.
        $kurse = [
            '01' => [['06:00:00', '06:20:00'], ['06:30:00', '06:50:00'], ['07:00:00', '07:20:00'], ['07:30:00', '07:50:00']],
            '02' => [['05:30:00', '05:50:00'], ['06:00:00', '06:20:00'], ['06:30:00', '06:50:00'], ['07:00:00', '07:20:00']],
        ];

        foreach ($kurse as $nummer => $fahrten) {
            $vorher = null;

            foreach ($fahrten as $i => [$ab, $an]) {
                // Abwechselnd hin und zurück — der Umlauf, wie er wirklich fährt.
                $fahrt = $this->f->fahrt($version, $i % 2 === 0 ? ['A', 'B'] : ['B', 'A'], [$ab, $an]);

                if ($vorher !== null) {
                    $this->verknuepfe($vorher, $fahrt);
                }

                $vorher = $fahrt;
            }

            $this->setzeKurs($vorher, $nummer);
        }

        $daten = $this->hole();

        $this->assertSame(['01', '02'], array_column($daten['courses'], 'number'), 'Die Spalten bleiben in Kursreihenfolge.');

        // Die Achse ist um eine Runde gewachsen: niemand wird abgeschnitten.
        $this->assertSame(['A', 'B', 'B', 'A', 'A', 'B', 'B', 'A', 'A', 'B', 'B', 'A'], array_column($daten['rows'], 'stop_name'));

        $this->assertSame(
            [null, null, null, null, '06:00:00', '06:20:00', '06:30:00', '06:50:00', '07:00:00', '07:20:00', '07:30:00', '07:50:00'],
            $this->zeiten($daten['courses'][0]),
        );
        $this->assertSame(
            ['05:30:00', '05:50:00', '06:00:00', '06:20:00', '06:30:00', '06:50:00', '07:00:00', '07:20:00', null, null, null, null],
            $this->zeiten($daten['courses'][1]),
        );

        // Und die Probe aufs Ganze: Jede voll belegte Zeile steigt quer gelesen auf.
        foreach ($daten['rows'] as $zeile) {
            $quer = array_map(
                static fn (array $s): ?string => $s['cells'][$zeile['position']]['time'] ?? null,
                $daten['courses'],
            );

            if (in_array(null, $quer, true)) {
                continue;
            }

            $sortiert = $quer;
            sort($sortiert);

            $this->assertSame($sortiert, $quer, "Zeile {$zeile['position']} läuft rückwärts.");
        }
    }

    /**
     * Ein Umlauf, der eine Runde weniger fährt, lässt seine Spalte unten leer — statt die
     * Zeilen der anderen zu verschieben.
     */
    public function test_a_shorter_course_leaves_empty_cells(): void
    {
        $version = $this->version();

        $lang1 = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $lang2 = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);
        $this->verknuepfe($lang1, $lang2);
        $this->setzeKurs($lang1, '01');

        $kurz = $this->f->fahrt($version, ['A', 'B'], ['06:10:00', '06:40:00']);
        $this->setzeKurs($kurz, '02');

        $daten = $this->hole();

        $this->assertSame(['06:00:00', '06:30:00', '06:35:00', '07:05:00'], $this->zeiten($daten['courses'][0]));
        $this->assertSame(['06:10:00', '06:40:00', null, null], $this->zeiten($daten['courses'][1]));
    }

    /**
     * Ein Umlauf läuft über Linien hinweg. Die Zelle trägt die Linie ihrer Fahrt — nur so ist
     * der Wechsel in der Spalte farbig zu unterlegen.
     */
    public function test_each_cell_carries_the_line_of_its_trip(): void
    {
        $eins = $this->version('1');
        $dreizehn = $this->version('13');

        $a = $this->f->fahrt($eins, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($dreizehn, ['B', 'C'], ['06:35:00', '07:05:00']);

        $this->verknuepfe($a, $b);
        $this->setzeKurs($a, '01');

        $spalte = $this->hole('1')['courses'][0];

        $this->assertSame(['1', '13'], $spalte['lines']);
        $this->assertSame(['1', '1', '13', '13'], array_map(static fn (?array $z): ?string => $z['line'] ?? null, $spalte['cells']));
    }

    /**
     * GTFS-Wallclock bleibt stehen: `00:19:00` auf einer Nachtlinie gehört zum Betriebstag des
     * Vortags und wird hier nicht zurückgerechnet.
     */
    public function test_times_stay_gtfs_wallclock(): void
    {
        $version = $this->version('N1');
        $a = $this->f->fahrt($version, ['A', 'B'], ['23:50:00', '00:19:00']);
        $this->setzeKurs($a, '01');

        $this->assertSame(['23:50:00', '00:19:00'], $this->zeiten($this->hole('N1')['courses'][0]));
    }

    public function test_summary_and_unassigned_match_the_chain_view(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $this->f->fahrt($version, ['A', 'B'], ['07:00:00', '07:30:00']);
        $this->setzeKurs($a, '01');

        $tabelle = $this->hole();

        $kette = $this->withToken($this->token())
            ->getJson("/api/v1/admin/lines/1/courses?period={$this->periode->id}&day_type=mo_fr")
            ->assertOk()
            ->json('data');

        // Der Umschalter soll den Pflegestand nicht verändern, nur die Darstellung.
        $this->assertSame($kette['summary'], $tabelle['summary']);
        $this->assertSame(
            array_column($kette['unassigned'], 'id'),
            array_column($tabelle['unassigned'], 'id'),
        );
    }
}
