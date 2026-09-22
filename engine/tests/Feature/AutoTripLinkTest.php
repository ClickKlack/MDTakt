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
 * Anschlüsse über einen Zeitraum automatisch verknüpfen und wieder auflösen.
 *
 * Der Lauf darf nur anfassen, was der Pflegende markiert und gefiltert hat — und er darf nie
 * überschreiben. Beides ist hier festgenagelt, ebenso die beiden Stellen, an denen der
 * Betriebstag anders rechnet als die Uhr.
 */
final class AutoTripLinkTest extends TestCase
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

    /**
     * Eine Version samt beobachteter Gültigkeit — ohne sie überschneiden sich zwei Versionen an
     * keinem Tag, und jeder Anschluss wäre unzulässig.
     */
    private function version(string $linie = '1', int $versionNo = 1, FahrplanTyp $typ = FahrplanTyp::MoFrNormal): LineVersion
    {
        $version = $this->f->version($linie, $typ, $versionNo, $this->f->periode());
        $this->f->gueltigkeit($version);

        return $version;
    }

    /** Eine Fahrt, die an `Sudenburg` endet. */
    private function ankunft(LineVersion $version, string $zeit, string $von = 'Kannenstieg'): ConsolidatedTrip
    {
        return $this->f->fahrt($version, [$von, 'Sudenburg'], ['05:00:00', $zeit]);
    }

    /** Eine Fahrt, die an `Sudenburg` beginnt. */
    private function abfahrt(LineVersion $version, string $zeit, string $nach = 'Westerhuesen'): ConsolidatedTrip
    {
        return $this->f->fahrt($version, ['Sudenburg', $nach], [$zeit, '23:00:00']);
    }

    /**
     * @param  array<string, mixed>  $zusatz
     * @return array<string, mixed>
     */
    private function rumpf(ConsolidatedTrip $von, ConsolidatedTrip $bis, array $zusatz = []): array
    {
        return [
            'stop_group' => $this->f->haltestelle('Sudenburg')->id,
            'period' => $this->f->periode()->id,
            'day_type' => FahrplanTyp::MoFrNormal->value,
            'from_trip_id' => $von->id,
            'to_trip_id' => $bis->id,
        ] + $zusatz;
    }

    /**
     * @param  array<string, mixed>  $rumpf
     * @return array<string, mixed>
     */
    private function vorschau(array $rumpf): array
    {
        return $this->withToken($this->token())
            ->getJson('/api/v1/admin/stop-links/auto?'.http_build_query($rumpf))
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $rumpf
     * @return array<string, mixed>
     */
    private function anwenden(array $rumpf): array
    {
        return $this->withToken($this->token())
            ->postJson('/api/v1/admin/stop-links/auto', $rumpf)
            ->assertOk()
            ->json('data');
    }

    // ---------------------------------------------------------------- Zugang und Vorschau

    public function test_requires_authentication(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:48:00');
        $ab = $this->abfahrt($version, '06:52:00');

        $this->getJson('/api/v1/admin/stop-links/auto?'.http_build_query($this->rumpf($an, $an)))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);

        $this->postJson('/api/v1/admin/stop-links/auto', $this->rumpf($an, $an))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);

        $this->assertSame(0, TripLink::query()->count(), 'Ein abgewiesener Aufruf darf nichts anlegen.');
        $this->assertNotNull($ab);
    }

    public function test_preview_changes_nothing(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:48:00');
        $this->abfahrt($version, '06:52:00');

        $daten = $this->vorschau($this->rumpf($an, $an));

        $this->assertFalse($daten['summary']['applied']);
        $this->assertSame(1, $daten['summary']['planned']);
        $this->assertSame(0, $daten['summary']['created']);
        $this->assertDatabaseCount('trip_links', 0);
    }

    public function test_apply_creates_the_previewed_pairs(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:48:00');
        $ab = $this->abfahrt($version, '06:52:00');

        $vorschau = $this->vorschau($this->rumpf($an, $an));
        $ergebnis = $this->anwenden($this->rumpf($an, $an));

        $this->assertSame($vorschau['summary']['planned'], $ergebnis['summary']['created']);
        $this->assertTrue($ergebnis['summary']['applied']);
        $this->assertDatabaseHas('trip_links', [
            'from_trip_id' => $an->id,
            'to_trip_id' => $ab->id,
            'kind' => TripLinkKind::Link->value,
        ]);
    }

    // ---------------------------------------------------------------- Die FIFO-Paarung

    public function test_each_arrival_gets_the_earliest_free_departure(): void
    {
        $version = $this->version();

        $an1 = $this->ankunft($version, '06:00:00');
        $an2 = $this->ankunft($version, '06:10:00');

        $ab1 = $this->abfahrt($version, '06:05:00');
        $ab2 = $this->abfahrt($version, '06:15:00');
        $ab3 = $this->abfahrt($version, '06:25:00');

        $daten = $this->anwenden($this->rumpf($an1, $an2));

        $this->assertSame(2, $daten['summary']['created']);
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $an1->id, 'to_trip_id' => $ab1->id]);
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $an2->id, 'to_trip_id' => $ab2->id]);
        $this->assertDatabaseMissing('trip_links', ['to_trip_id' => $ab3->id]);
    }

    public function test_turnaround_exactly_at_the_minimum_is_used(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');
        $ab = $this->abfahrt($version, '06:03:00');

        $daten = $this->anwenden($this->rumpf($an, $an, ['min_turnaround_minutes' => 3]));

        $this->assertSame(1, $daten['summary']['created']);
        $this->assertSame(180, $daten['pairs'][0]['turnaround_seconds']);
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $an->id, 'to_trip_id' => $ab->id]);
    }

    public function test_turnaround_one_second_below_the_minimum_is_not_used(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');
        $this->abfahrt($version, '06:02:59');

        $daten = $this->anwenden($this->rumpf($an, $an, ['min_turnaround_minutes' => 3]));

        $this->assertSame(0, $daten['summary']['created']);
        $this->assertSame('no_partner', $daten['skipped'][0]['reason_code']);
    }

    public function test_turnaround_exactly_at_the_maximum_is_used(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');
        $ab = $this->abfahrt($version, '06:20:00');

        $daten = $this->anwenden($this->rumpf($an, $an, ['min_turnaround_minutes' => 0, 'max_turnaround_minutes' => 20]));

        $this->assertSame(1, $daten['summary']['created']);
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $an->id, 'to_trip_id' => $ab->id]);
    }

    public function test_turnaround_one_second_above_the_maximum_is_not_used(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');
        $this->abfahrt($version, '06:20:01');

        $daten = $this->anwenden($this->rumpf($an, $an, ['min_turnaround_minutes' => 0, 'max_turnaround_minutes' => 20]));

        $this->assertSame(0, $daten['summary']['created']);
        $this->assertSame('no_partner', $daten['skipped'][0]['reason_code']);
        $this->assertDatabaseCount('trip_links', 0);
    }

    public function test_planned_pairs_block_each_other(): void
    {
        $version = $this->version();

        $an1 = $this->ankunft($version, '06:00:00');
        $an2 = $this->ankunft($version, '06:01:00');
        $ab = $this->abfahrt($version, '06:10:00');

        $daten = $this->anwenden($this->rumpf($an1, $an2));

        // Nur eine passende Abfahrt: Die zweite Ankunft geht leer aus, statt dieselbe Abfahrt
        // ein zweites Mal zu bekommen — der Unique-Constraint auf to_trip_id schlüge sonst zu.
        $this->assertSame(1, $daten['summary']['created']);
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $an1->id, 'to_trip_id' => $ab->id]);
        $this->assertDatabaseMissing('trip_links', ['from_trip_id' => $an2->id]);
    }

    public function test_a_turnaround_loop_does_not_link_a_trip_to_itself(): void
    {
        $version = $this->version();

        // Eine Fahrt, die an Sudenburg endet **und** beginnt — der Wendeschleifen-Fall, der im
        // Realbestand 1.022 von 18.193 Fahrten betrifft. Sie steht in beiden Spalten und wäre
        // sonst ihr eigener Anschluss.
        $wende = $this->f->fahrt($version, ['Sudenburg', 'Kannenstieg', 'Sudenburg'], ['06:00:00', '06:20:00', '06:40:00']);
        $ab = $this->abfahrt($version, '06:45:00');

        $daten = $this->anwenden($this->rumpf($wende, $wende));

        $this->assertSame(1, $daten['summary']['created']);
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $wende->id, 'to_trip_id' => $ab->id]);
        $this->assertDatabaseMissing('trip_links', ['from_trip_id' => $wende->id, 'to_trip_id' => $wende->id]);
    }

    // ---------------------------------------------------------------- Filter und Bereich

    public function test_only_the_filtered_lines_are_paired(): void
    {
        $eins = $this->version('1');
        $dreizehn = $this->version('13');
        $sechs = $this->version('6');

        $an = $this->ankunft($eins, '06:00:00');
        $ab13 = $this->abfahrt($dreizehn, '06:05:00');

        $an6 = $this->ankunft($sechs, '06:02:00');
        $ab6 = $this->abfahrt($sechs, '06:07:00');

        $daten = $this->anwenden($this->rumpf($an, $an, ['lines' => ['1', '13']]));

        $this->assertSame(1, $daten['summary']['created']);
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $an->id, 'to_trip_id' => $ab13->id]);

        // Die 6 steht an derselben Haltestelle und passte zeitlich — sie bleibt unberührt,
        // weil sie nicht im Filter steht. Genau dafür gibt es ihn.
        $this->assertDatabaseMissing('trip_links', ['from_trip_id' => $an6->id]);
        $this->assertDatabaseMissing('trip_links', ['to_trip_id' => $ab6->id]);
    }

    public function test_only_the_marked_range_is_touched(): void
    {
        $version = $this->version();

        $davor = $this->ankunft($version, '05:00:00');
        $von = $this->ankunft($version, '06:00:00');
        $bis = $this->ankunft($version, '06:10:00');
        $danach = $this->ankunft($version, '07:00:00');

        $this->abfahrt($version, '05:05:00');
        $this->abfahrt($version, '06:05:00');
        $this->abfahrt($version, '06:15:00');
        $this->abfahrt($version, '07:05:00');

        $daten = $this->anwenden($this->rumpf($von, $bis));

        $this->assertSame(2, $daten['summary']['created']);
        $this->assertDatabaseMissing('trip_links', ['from_trip_id' => $davor->id]);
        $this->assertDatabaseMissing('trip_links', ['from_trip_id' => $danach->id]);
    }

    public function test_range_marked_backwards_is_swapped(): void
    {
        $version = $this->version();

        $von = $this->ankunft($version, '06:00:00');
        $bis = $this->ankunft($version, '06:10:00');
        $this->abfahrt($version, '06:05:00');
        $this->abfahrt($version, '06:15:00');

        $daten = $this->anwenden($this->rumpf($bis, $von));

        $this->assertSame(2, $daten['summary']['created']);
    }

    public function test_marked_trip_outside_the_filter_is_rejected(): void
    {
        $eins = $this->version('1');
        $sechs = $this->version('6');

        $an1 = $this->ankunft($eins, '06:00:00');
        $an6 = $this->ankunft($sechs, '06:02:00');
        $this->abfahrt($eins, '06:05:00');

        $this->withToken($this->token())
            ->getJson('/api/v1/admin/stop-links/auto?'.http_build_query(
                $this->rumpf($an1, $an6, ['lines' => ['1']])
            ))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);

        $this->withToken($this->token())
            ->postJson('/api/v1/admin/stop-links/auto', $this->rumpf($an1, $an6, ['lines' => ['1']]))
            ->assertStatus(422);

        $this->assertDatabaseCount('trip_links', 0);
    }

    public function test_maximum_below_minimum_is_rejected(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');

        $this->withToken($this->token())
            ->postJson('/api/v1/admin/stop-links/auto', $this->rumpf($an, $an, [
                'min_turnaround_minutes' => 20,
                'max_turnaround_minutes' => 5,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    // ---------------------------------------------------------------- Übersprungenes

    public function test_trip_with_an_existing_decision_is_skipped(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');
        $this->abfahrt($version, '06:05:00');

        TripLink::query()->create([
            'from_trip_id' => $an->id,
            'to_trip_id' => null,
            'stop_id' => $an->last_stop_id,
            'kind' => TripLinkKind::End,
        ]);

        $daten = $this->anwenden($this->rumpf($an, $an));

        $this->assertSame(0, $daten['summary']['created']);
        $this->assertSame('trip_decided', $daten['skipped'][0]['reason_code']);
    }

    public function test_occupied_departure_is_passed_over_and_reported(): void
    {
        $version = $this->version();

        $an = $this->ankunft($version, '06:00:00');
        $belegt = $this->abfahrt($version, '06:05:00');
        $frei = $this->abfahrt($version, '06:10:00');

        TripLink::query()->create([
            'from_trip_id' => null,
            'to_trip_id' => $belegt->id,
            'stop_id' => $belegt->first_stop_id,
            'kind' => TripLinkKind::Start,
        ]);

        $daten = $this->anwenden($this->rumpf($an, $an));

        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $an->id, 'to_trip_id' => $frei->id]);

        $gemeldet = array_filter(
            $daten['skipped'],
            static fn (array $z): bool => $z['reason_code'] === 'departure_decided',
        );

        $this->assertCount(1, $gemeldet, 'Die belegte Abfahrt muss erklären, warum sie ausfiel.');
    }

    public function test_no_partner_in_the_window_is_reported(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');
        $this->abfahrt($version, '09:00:00');

        $daten = $this->anwenden($this->rumpf($an, $an));

        $this->assertSame(0, $daten['summary']['created']);
        $this->assertSame('no_partner', $daten['skipped'][0]['reason_code']);
        $this->assertDatabaseCount('trip_links', 0);
    }

    public function test_mode_change_is_reported_and_the_run_continues(): void
    {
        $tram = $this->version('1');
        $bus = $this->version('73');

        $an = $this->ankunft($tram, '06:00:00');

        $busAbfahrt = $this->abfahrt($bus, '06:05:00');
        $busAbfahrt->update(['route_type' => 3]);

        $tramAbfahrt = $this->abfahrt($tram, '06:10:00');

        $daten = $this->anwenden($this->rumpf($an, $an));

        // Der Bus wird abgewiesen, die Tram rückt nach: Ein Fahrzeug wechselt die Gattung
        // nicht, aber ein Nein darf den Lauf nicht beenden.
        $this->assertSame(1, $daten['summary']['created']);
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $an->id, 'to_trip_id' => $tramAbfahrt->id]);
        $this->assertDatabaseMissing('trip_links', ['to_trip_id' => $busAbfahrt->id]);
    }

    public function test_apply_is_idempotent(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');
        $this->abfahrt($version, '06:05:00');

        $this->anwenden($this->rumpf($an, $an));
        $zweiter = $this->anwenden($this->rumpf($an, $an));

        $this->assertSame(0, $zweiter['summary']['created']);
        $this->assertSame('trip_decided', $zweiter['skipped'][0]['reason_code']);
        $this->assertDatabaseCount('trip_links', 1);
    }

    // ---------------------------------------------------------------- Betriebstag statt Uhr

    public function test_night_line_pairing_across_midnight(): void
    {
        $nacht = $this->version('N1');

        // Der Feed notiert keine Zeiten jenseits 24:00: Viertel nach zwölf steht als 00:19.
        // Nach der Uhr wäre die Wendezeit negativ, auf dem Betriebstag sind es 59 Minuten.
        $an = $this->f->fahrt($nacht, ['Kannenstieg', 'Sudenburg'], ['22:40:00', '23:20:00']);
        $ab = $this->f->fahrt($nacht, ['Sudenburg', 'Westerhuesen'], ['00:19:00', '01:00:00']);

        $daten = $this->anwenden($this->rumpf($an, $an, ['max_turnaround_minutes' => 60]));

        $this->assertSame(1, $daten['summary']['created']);
        $this->assertSame(3540, $daten['pairs'][0]['turnaround_seconds']);
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $an->id, 'to_trip_id' => $ab->id]);
    }

    public function test_night_line_pairing_is_cut_off_by_the_maximum(): void
    {
        $nacht = $this->version('N1');

        $an = $this->f->fahrt($nacht, ['Kannenstieg', 'Sudenburg'], ['22:40:00', '23:20:00']);
        $this->f->fahrt($nacht, ['Sudenburg', 'Westerhuesen'], ['00:19:00', '01:00:00']);

        $daten = $this->anwenden($this->rumpf($an, $an, ['max_turnaround_minutes' => 30]));

        $this->assertSame(0, $daten['summary']['created'], '59 Minuten Wende liegen über der Grenze von 30.');
        $this->assertDatabaseCount('trip_links', 0);
    }

    public function test_night_to_day_handover_reports_negative_turnaround(): void
    {
        // Jede Seite trägt die Betriebstag-Grenze ihrer eigenen Linie: Nachtlinien 12:00,
        // Taglinien 03:00. Eine N1-Ankunft um 05:00 sortiert deshalb hinter eine Abfahrt der
        // Linie 1 um 05:20 — die Differenz ist stark negativ. Das ist kein Fehler dieses
        // Laufs, muss aber verständlich gemeldet werden und darf ihn nicht abbrechen.
        $nacht = $this->version('N1');
        $tag = $this->version('1');

        $an = $this->f->fahrt($nacht, ['Kannenstieg', 'Sudenburg'], ['04:20:00', '05:00:00']);
        $this->f->fahrt($tag, ['Sudenburg', 'Westerhuesen'], ['05:20:00', '06:00:00']);

        $daten = $this->anwenden($this->rumpf($an, $an, ['max_turnaround_minutes' => 600]));

        $this->assertSame(0, $daten['summary']['created']);
        $this->assertSame('negative_turnaround', $daten['skipped'][0]['reason_code']);
        $this->assertDatabaseCount('trip_links', 0);
    }

    // ---------------------------------------------------------------- Die Kursnummer folgt mit

    public function test_course_is_unified_after_each_link(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');
        $ab = $this->abfahrt($version, '06:05:00');

        $kurs = Course::query()->create([
            'period_id' => $this->f->periode()->id,
            'day_type' => FahrplanTyp::MoFrNormal->value,
            'number' => '03',
        ]);
        CourseTrip::query()->create(['course_id' => $kurs->id, 'consolidated_trip_id' => $an->id]);

        $daten = $this->anwenden($this->rumpf($an, $an));

        $this->assertSame(1, $daten['summary']['courses_unified']);
        $this->assertSame(0, $daten['summary']['course_conflicts']);
        $this->assertDatabaseHas('course_trips', ['course_id' => $kurs->id, 'consolidated_trip_id' => $ab->id]);
    }

    public function test_conflicting_courses_are_reported_not_resolved(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');
        $ab = $this->abfahrt($version, '06:05:00');

        foreach ([[$an, '03'], [$ab, '04']] as [$fahrt, $nummer]) {
            $kurs = Course::query()->create([
                'period_id' => $this->f->periode()->id,
                'day_type' => FahrplanTyp::MoFrNormal->value,
                'number' => $nummer,
            ]);
            CourseTrip::query()->create(['course_id' => $kurs->id, 'consolidated_trip_id' => $fahrt->id]);
        }

        $daten = $this->anwenden($this->rumpf($an, $an));

        $this->assertSame(1, $daten['summary']['course_conflicts']);

        // Der Anschluss bleibt bestehen: Er ist eine Aussage über das Fahrzeug, der Kurs nur
        // sein Etikett. Welche Nummer die richtige ist, weiß nur der Pflegende.
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $an->id, 'to_trip_id' => $ab->id]);
        $this->assertDatabaseHas('course_trips', ['consolidated_trip_id' => $an->id]);
        $this->assertDatabaseHas('course_trips', ['consolidated_trip_id' => $ab->id]);
    }

    // ---------------------------------------------------------------- Tauschpunkt

    /**
     * Baut die Kreuzung vom City Carré nach: zwei Bahnsteige einer Haltestelle, an denen je
     * eine Fahrt endet und eine **andere** Linie weiterfährt.
     *
     * Beide Ankünfte liegen auf derselben Sekunde, beide Abfahrten ebenfalls — die Zeit
     * unterscheidet hier also nichts. Was bleibt, ist der Halt: Das Fahrzeug steht kurz und
     * fährt von dort weiter, es wechselt den Bahnsteig nicht.
     *
     * @return array{0: ConsolidatedTrip, 1: ConsolidatedTrip, 2: ConsolidatedTrip, 3: ConsolidatedTrip}
     *                                                                                                   Ankunft A, Ankunft B, Abfahrt ab A, Abfahrt ab B
     */
    private function tauschpunkt(): array
    {
        $eins = $this->version('1');
        $fuenf = $this->version('5');

        $gruppe = $this->f->haltestelle('City Carree');

        // Zwei Bahnsteige **einer** Haltestelle. Ohne die Klammer wiese die Zulässigkeits-
        // pruefung jeden Uebergang ab, weil die Halte verschieden sind.
        foreach (['Carree Nord', 'Carree Sued'] as $steig) {
            $this->f->zurHaltestelle($this->f->halt($steig), $gruppe);
        }

        // Linie 5 kommt von der Goldschmiedebruecke und endet auf dem Nordsteig; von dort
        // faehrt die 1 zum Hauptbahnhof weiter.
        $anNord = $this->f->fahrt($fuenf, ['Goldschmiedebruecke', 'Carree Nord'], ['04:30:00', '04:36:00']);
        $abNord = $this->f->fahrt($eins, ['Carree Nord', 'Hauptbahnhof'], ['04:38:00', '04:50:00']);

        // Die 1 kommt vom Hauptbahnhof und endet auf dem Suedsteig; von dort faehrt die 5
        // zur Leiterstrasse weiter.
        $anSued = $this->f->fahrt($eins, ['Hauptbahnhof', 'Carree Sued'], ['04:28:00', '04:36:00']);
        $abSued = $this->f->fahrt($fuenf, ['Carree Sued', 'Leiterstrasse'], ['04:38:00', '04:52:00']);

        return [$anNord, $anSued, $abNord, $abSued];
    }

    /**
     * @param  array<string, mixed>  $zusatz
     * @return array<string, mixed>
     */
    private function tauschRumpf(ConsolidatedTrip $von, ConsolidatedTrip $bis, array $zusatz = []): array
    {
        return [
            'stop_group' => $this->f->haltestelle('City Carree')->id,
            'period' => $this->f->periode()->id,
            'day_type' => FahrplanTyp::MoFrNormal->value,
            'from_trip_id' => $von->id,
            'to_trip_id' => $bis->id,
            'min_turnaround_minutes' => 0,
        ] + $zusatz;
    }

    /**
     * Der gemeldete Fall: Beide Abfahrten liegen auf derselben Sekunde, und ohne den Schalter
     * entscheidet die Datenbank-Id — die paart zweimal die Kehrtwende, also das Fahrzeug
     * zurueck dorthin, wo es hergekommen ist.
     */
    public function test_a_through_stop_pairs_by_platform_not_by_id(): void
    {
        [$anNord, $anSued, $abNord, $abSued] = $this->tauschpunkt();

        $daten = $this->anwenden($this->tauschRumpf($anNord, $anSued, ['through_stop' => true]));

        $this->assertSame(2, $daten['summary']['created']);

        $paare = [];
        foreach ($daten['pairs'] as $paar) {
            $paare[$paar['from_trip']['id']] = $paar['to_trip']['id'];
        }

        // Wer auf dem Nordsteig ankommt, faehrt vom Nordsteig weiter.
        $this->assertSame($abNord->id, $paare[$anNord->id] ?? null);
        $this->assertSame($abSued->id, $paare[$anSued->id] ?? null);
    }

    /** Ohne den Schalter bleibt es beim Heute: Die Zeit fuehrt, der Halt zaehlt nicht. */
    public function test_without_the_switch_the_platform_is_ignored(): void
    {
        [$anNord, $anSued] = $this->tauschpunkt();

        $daten = $this->vorschau($this->tauschRumpf($anNord, $anSued));

        $this->assertSame(2, $daten['summary']['planned']);
        $this->assertFalse($daten['filter']['through_stop']);
    }

    /**
     * An einer Endstelle mit **zwei** Bahnsteigen wechselt das Fahrzeug die Seite — netzweit ist
     * das der Normalfall (64 von 104 Endstellen). Der Schalter findet dort nichts und sagt das,
     * statt still nichts zu tun.
     *
     * An einer Wendeschleife mit nur einem Halt greift er dagegen gar nicht: Dort steht das
     * Fahrzeug tatsaechlich am selben Punkt, und der Schalter ist folgenlos.
     */
    public function test_a_through_stop_run_at_a_two_sided_terminus_reports_why_nothing_matched(): void
    {
        $version = $this->version();

        $gruppe = $this->f->haltestelle('Herrenkrug');

        foreach (['Herrenkrug Ankunft', 'Herrenkrug Abfahrt'] as $steig) {
            $this->f->zurHaltestelle($this->f->halt($steig), $gruppe);
        }

        $an = $this->f->fahrt($version, ['Kannenstieg', 'Herrenkrug Ankunft'], ['05:00:00', '06:00:00']);
        $this->f->fahrt($version, ['Herrenkrug Abfahrt', 'Kannenstieg'], ['06:05:00', '07:00:00']);

        $rumpf = [
            'stop_group' => $gruppe->id,
            'period' => $this->f->periode()->id,
            'day_type' => FahrplanTyp::MoFrNormal->value,
            'from_trip_id' => $an->id,
            'to_trip_id' => $an->id,
            'min_turnaround_minutes' => 0,
        ];

        // Ohne Schalter wird gepaart, mit Schalter nicht — derselbe Ausschnitt.
        $this->assertSame(1, $this->vorschau($rumpf)['summary']['planned']);

        $daten = $this->vorschau($rumpf + ['through_stop' => true]);

        $this->assertSame(0, $daten['summary']['planned']);
        $this->assertSame('different_platform', $daten['skipped'][0]['reason_code']);
    }

    /** Der Schalter kommt bei der Vorschau als Text an — wie `include_terminals`. */
    public function test_the_through_stop_switch_is_accepted_as_query_text(): void
    {
        [$anNord, $anSued] = $this->tauschpunkt();

        $abfrage = http_build_query($this->tauschRumpf($anNord, $anSued)).'&through_stop=true';

        $daten = $this->withToken($this->token())
            ->getJson('/api/v1/admin/stop-links/auto?'.$abfrage)
            ->assertOk()
            ->json('data');

        $this->assertTrue($daten['filter']['through_stop']);
    }

    // ---------------------------------------------------------------- Auflösen

    public function test_unlink_preview_changes_nothing(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');
        $this->abfahrt($version, '06:05:00');

        $this->anwenden($this->rumpf($an, $an));

        $daten = $this->vorschau($this->rumpf($an, $an, ['action' => 'unlink']));

        $this->assertFalse($daten['summary']['applied']);
        $this->assertSame(1, $daten['summary']['planned']);
        $this->assertDatabaseCount('trip_links', 1);
    }

    public function test_unlink_removes_the_links_in_range(): void
    {
        $version = $this->version();

        $von = $this->ankunft($version, '06:00:00');
        $bis = $this->ankunft($version, '06:10:00');
        $this->abfahrt($version, '06:05:00');
        $this->abfahrt($version, '06:15:00');

        $this->anwenden($this->rumpf($von, $bis));
        $this->assertDatabaseCount('trip_links', 2);

        $daten = $this->anwenden($this->rumpf($von, $bis, ['action' => 'unlink']));

        $this->assertSame(2, $daten['summary']['removed']);
        $this->assertSame(0, $daten['summary']['created']);
        $this->assertDatabaseCount('trip_links', 0);
    }

    public function test_unlink_leaves_links_outside_the_range_alone(): void
    {
        $version = $this->version();

        $davor = $this->ankunft($version, '05:00:00');
        $drin = $this->ankunft($version, '06:00:00');
        $this->abfahrt($version, '05:05:00');
        $this->abfahrt($version, '06:05:00');

        $this->anwenden($this->rumpf($davor, $drin));
        $this->assertDatabaseCount('trip_links', 2);

        $this->anwenden($this->rumpf($drin, $drin, ['action' => 'unlink']));

        $this->assertDatabaseCount('trip_links', 1);
        $this->assertDatabaseHas('trip_links', ['from_trip_id' => $davor->id]);
    }

    public function test_unlink_keeps_the_course_on_both_halves(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');
        $ab = $this->abfahrt($version, '06:05:00');

        $kurs = Course::query()->create([
            'period_id' => $this->f->periode()->id,
            'day_type' => FahrplanTyp::MoFrNormal->value,
            'number' => '03',
        ]);
        CourseTrip::query()->create(['course_id' => $kurs->id, 'consolidated_trip_id' => $an->id]);

        $this->anwenden($this->rumpf($an, $an));
        $this->anwenden($this->rumpf($an, $an, ['action' => 'unlink']));

        // Die zerschnittene Kette zerfällt in zwei Teile, die beide weiter dieselbe Nummer
        // tragen — die Zuordnung wird bewahrt und nicht geraten.
        $this->assertDatabaseCount('trip_links', 0);
        $this->assertDatabaseHas('course_trips', ['course_id' => $kurs->id, 'consolidated_trip_id' => $an->id]);
        $this->assertDatabaseHas('course_trips', ['course_id' => $kurs->id, 'consolidated_trip_id' => $ab->id]);
    }

    public function test_unlink_skips_a_trip_without_a_decision(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');
        $this->abfahrt($version, '06:05:00');

        $daten = $this->anwenden($this->rumpf($an, $an, ['action' => 'unlink']));

        $this->assertSame(0, $daten['summary']['removed']);
        $this->assertSame('not_decided', $daten['skipped'][0]['reason_code']);
    }

    public function test_unlink_keeps_terminals_unless_asked(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');

        TripLink::query()->create([
            'from_trip_id' => $an->id,
            'to_trip_id' => null,
            'stop_id' => $an->last_stop_id,
            'kind' => TripLinkKind::End,
        ]);

        $ohne = $this->anwenden($this->rumpf($an, $an, ['action' => 'unlink']));

        $this->assertSame(0, $ohne['summary']['removed']);
        $this->assertSame('terminal_decision', $ohne['skipped'][0]['reason_code']);
        $this->assertDatabaseCount('trip_links', 1);

        $mit = $this->anwenden($this->rumpf($an, $an, ['action' => 'unlink', 'include_terminals' => true]));

        $this->assertSame(1, $mit['summary']['removed']);
        $this->assertDatabaseCount('trip_links', 0);
    }

    /**
     * Eine Query-Zeichenkette kennt keine Booleans: axios schreibt den ungesetzten Haken als
     * `include_terminals=false`, und Laravels `boolean`-Regel nimmt genau diese Schreibweise
     * nicht an. Ohne die Umschreibung im Request scheiterte **jede** Vorschau des Auflösens
     * mit 422 — der Knopf zum Ausführen erschien deshalb nie.
     *
     * `http_build_query` schreibt `true` als `1` und trifft den Fall nicht; die Query steht hier
     * deshalb von Hand.
     */
    public function test_unlink_preview_accepts_the_terminal_switch_as_query_text(): void
    {
        $version = $this->version();
        $an = $this->ankunft($version, '06:00:00');
        $this->abfahrt($version, '06:05:00');

        $this->anwenden($this->rumpf($an, $an));

        foreach (['false' => 1, 'true' => 1] as $text => $erwartet) {
            $rumpf = $this->rumpf($an, $an, ['action' => 'unlink']);
            $abfrage = http_build_query($rumpf).'&include_terminals='.$text;

            $daten = $this->withToken($this->token())
                ->getJson('/api/v1/admin/stop-links/auto?'.$abfrage)
                ->assertOk()
                ->json('data');

            $this->assertSame($erwartet, $daten['summary']['planned'], "include_terminals={$text}");
            $this->assertSame($text === 'true', $daten['filter']['include_terminals']);
        }
    }

    public function test_unlink_reports_a_partner_outside_the_range(): void
    {
        $version = $this->version();

        $an1 = $this->ankunft($version, '06:00:00');
        $an2 = $this->ankunft($version, '06:10:00');
        $this->abfahrt($version, '06:05:00');
        $this->abfahrt($version, '06:15:00');

        $this->anwenden($this->rumpf($an1, $an2));

        // Nur die zweite Ankunft markieren — ihre Abfahrt steht nicht in der linken Spalte und
        // liegt damit definitionsgemäß außerhalb des Bereichs.
        $daten = $this->anwenden($this->rumpf($an2, $an2, ['action' => 'unlink']));

        $this->assertSame(1, $daten['summary']['removed']);
        $this->assertTrue($daten['removals'][0]['partner_outside_range']);
    }

    public function test_unlink_restores_the_state_before_the_run(): void
    {
        $version = $this->version();

        $von = $this->ankunft($version, '06:00:00');
        $bis = $this->ankunft($version, '06:10:00');
        $this->abfahrt($version, '06:05:00');
        $this->abfahrt($version, '06:15:00');

        $this->anwenden($this->rumpf($von, $bis));
        $this->anwenden($this->rumpf($von, $bis, ['action' => 'unlink']));

        $this->assertDatabaseCount('trip_links', 0);

        $zweiter = $this->anwenden($this->rumpf($von, $bis, ['action' => 'unlink']));

        $this->assertSame(0, $zweiter['summary']['removed'], 'Ein zweiter Lauf ist folgenlos.');
    }
}
