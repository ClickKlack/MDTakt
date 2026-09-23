<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Models\ConsolidatedTrip;
use App\Models\Depot;
use App\Models\SchedulePeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Der Haltestellen-Editor: endende und beginnende Fahrten samt Entscheidungen (KURSE §1),
 * und die Zerlegung der Periode in Versionsstände (K5).
 */
final class StopLinkBoardTest extends TestCase
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

    private function haltestelleId(string $name): int
    {
        return $this->f->haltestelle($name)->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function hole(int $gruppeId, SchedulePeriod $periode, FahrplanTyp $typ = FahrplanTyp::MoFrNormal, ?int $stand = null): array
    {
        $query = http_build_query(array_filter([
            'stop_group' => $gruppeId,
            'period' => $periode->id,
            'day_type' => $typ->value,
            'stand' => $stand,
        ], static fn ($v): bool => $v !== null));

        return $this->withToken($this->token())
            ->getJson("/api/v1/admin/stop-links?{$query}")
            ->assertOk()
            ->json('data');
    }

    public function test_requires_authentication(): void
    {
        $periode = $this->f->periode();
        $gruppe = $this->f->haltestelle('Sudenburg');

        $this->getJson("/api/v1/admin/stop-links?stop_group={$gruppe->id}&period={$periode->id}&day_type=mo_fr")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_missing_filters_are_rejected(): void
    {
        $this->withToken($this->token())
            ->getJson('/api/v1/admin/stop-links')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_unknown_day_type_is_rejected(): void
    {
        $periode = $this->f->periode();
        $gruppe = $this->f->haltestelle('Sudenburg');

        $this->withToken($this->token())
            ->getJson("/api/v1/admin/stop-links?stop_group={$gruppe->id}&period={$periode->id}&day_type=montags")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_trips_are_split_into_ending_and_starting(): void
    {
        $periode = $this->f->periode();
        $version = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($version, '2026-08-17', '2026-09-18');

        $endet = $this->f->fahrt($version, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);
        $beginnt = $this->f->fahrt($version, ['Sudenburg', 'Kannenstieg'], ['06:52:00', '07:26:00']);
        // Beruehrt Sudenburg nur unterwegs — gehoert in keine der beiden Listen.
        $this->f->fahrt($version, ['Kannenstieg', 'Sudenburg', 'Westerhüsen'], ['07:00:00', '07:34:00', '08:00:00']);

        $daten = $this->hole($this->haltestelleId('Sudenburg'), $periode);

        $this->assertSame([$endet->id], array_column($daten['ending'], 'id'));
        $this->assertSame([$beginnt->id], array_column($daten['starting'], 'id'));
        $this->assertSame(2, $daten['open_count']);
        $this->assertSame('Sudenburg', $daten['stop_group']['name']);
        $this->assertSame('mo_fr', $daten['day_type']);
    }

    /**
     * Der Fall, an dem die erste Fassung scheiterte: An einer Endstelle liegen Ankunft und
     * Abfahrt auf **verschiedenen** Halten. An „Herrenkrug" enden Fahrten auf dem einen
     * Bahnsteig und beginnen 72 m weiter auf dem anderen; netzweit sind 64 von 104 Endstellen
     * so gebaut. Prüfte der Editor auf Halt-Identität, wäre hier kein Anschluss möglich.
     */
    public function test_arrival_and_departure_platforms_belong_to_one_stop_group(): void
    {
        $periode = $this->f->periode();
        $version = $this->f->version('6', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($version);

        // Zwei Halte, die nur der Betrieb zusammenhält — die Konsolidierung trennt sie,
        // weil sie mehr als 12 m auseinanderliegen.
        $ankunft = $this->f->halt('Herrenkrug Ankunft');
        $abfahrt = $this->f->halt('Herrenkrug Abfahrt');
        $haltestelle = $this->f->haltestelle('Herrenkrug');
        $this->f->zurHaltestelle($ankunft, $haltestelle);
        $this->f->zurHaltestelle($abfahrt, $haltestelle);

        $endet = $this->f->fahrt($version, ['Alter Markt', 'Herrenkrug Ankunft'], ['06:14:00', '06:48:00']);
        $beginnt = $this->f->fahrt($version, ['Herrenkrug Abfahrt', 'Alter Markt'], ['06:52:00', '07:26:00']);

        $daten = $this->hole($haltestelle->id, $periode);

        // Beide Bahnsteige sind sichtbar — die Klammer versteckt sie nicht.
        $this->assertCount(2, $daten['stop_group']['stops']);
        $this->assertSame([$endet->id], array_column($daten['ending'], 'id'));
        $this->assertSame([$beginnt->id], array_column($daten['starting'], 'id'));

        // Und der Anschluss über die Bahnsteiggrenze hinweg ist zulässig.
        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'link',
            'from_trip_id' => $endet->id,
            'to_trip_id' => $beginnt->id,
        ])->assertCreated()->assertJsonPath('data.turnaround_seconds', 240);
    }

    /**
     * Die Klammer darf nicht beliebig weit reichen: Zwei Halte, die verschiedenen
     * Haltestellen angehören, bilden keinen Anschluss — das ist der Betriebsfahrt-Fall
     * (`Südring` → `Eiskellerplatz`, 484 m) und gehört als solcher festgehalten.
     */
    public function test_stops_of_different_stop_groups_cannot_be_linked(): void
    {
        $periode = $this->f->periode();
        $version = $this->f->version('5', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($version);

        $endet = $this->f->fahrt($version, ['Alter Markt', 'Südring'], ['06:14:00', '06:48:00']);
        $beginnt = $this->f->fahrt($version, ['Eiskellerplatz', 'Alter Markt'], ['06:52:00', '07:26:00']);

        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'link',
            'from_trip_id' => $endet->id,
            'to_trip_id' => $beginnt->id,
        ])->assertStatus(422)->assertJsonPath('error.code', 422);
    }

    /**
     * Wendeschleife: Dieselbe Fahrt endet und beginnt am selben Punkt. Sie steht in beiden
     * Listen, weil es zwei getrennte Entscheidungen sind — Vorgänger und Nachfolger.
     */
    public function test_a_loop_trip_appears_in_both_columns(): void
    {
        $periode = $this->f->periode();
        $version = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($version);

        $rund = $this->f->fahrt($version, ['Sudenburg', 'Westerhüsen', 'Sudenburg'], ['06:00:00', '06:20:00', '06:40:00']);

        $daten = $this->hole($this->haltestelleId('Sudenburg'), $periode);

        $this->assertSame([$rund->id], array_column($daten['ending'], 'id'));
        $this->assertSame([$rund->id], array_column($daten['starting'], 'id'));
    }

    /**
     * Sortiert wird entlang des **Betriebstags**, nicht der Uhr. Auf der N1 beginnt der
     * Betriebstag abends: 23:20 fährt zuerst, dann die Fahrt über Mitternacht, und zuletzt
     * die Morgenfahrt um 06:35 — die gehört zum selben Betriebstag, obwohl ihre Uhrzeit die
     * kleinste ist. Nach der Uhr sortiert stünde die halbe Nacht am Anfang.
     */
    public function test_columns_are_sorted_along_the_operating_day(): void
    {
        $periode = $this->f->periode();
        $version = $this->f->version('N1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($version);

        $spaet = $this->f->fahrt($version, ['A', 'Reform'], ['23:20:00', '23:50:00']);
        $ueberMitternacht = $this->f->fahrt($version, ['A', 'Reform'], ['24:20:00', '24:50:00']);
        $frueh = $this->f->fahrt($version, ['A', 'Reform'], ['6:35:00', '7:05:00']);

        $daten = $this->hole($this->haltestelleId('Reform'), $periode);

        $this->assertSame(
            [$spaet->id, $ueberMitternacht->id, $frueh->id],
            array_column($daten['ending'], 'id'),
        );
    }

    public function test_decisions_are_attached_to_both_sides(): void
    {
        $periode = $this->f->periode();
        $version = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($version);

        $endet = $this->f->fahrt($version, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);
        $beginnt = $this->f->fahrt($version, ['Sudenburg', 'Kannenstieg'], ['06:52:00', '07:26:00']);

        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'link',
            'from_trip_id' => $endet->id,
            'to_trip_id' => $beginnt->id,
        ])->assertCreated();

        $daten = $this->hole($this->haltestelleId('Sudenburg'), $periode);

        $links = $daten['ending'][0]['decision'];
        $rechts = $daten['starting'][0]['decision'];

        $this->assertSame('link', $links['kind']);
        $this->assertSame($beginnt->id, $links['partner']['id']);
        $this->assertSame(240, $links['turnaround_seconds']);

        $this->assertSame('link', $rechts['kind']);
        $this->assertSame($endet->id, $rechts['partner']['id']);

        // Beide Seiten sind entschieden — nichts bleibt offen.
        $this->assertSame(0, $daten['open_count']);
    }

    /**
     * Der Betriebsfahrt-Fall. Er darf nicht als "ungepflegt" zählen — sonst wäre der
     * Pflegestand nie erreichbar.
     */
    public function test_a_deliberate_open_end_does_not_count_as_unpflegt(): void
    {
        $periode = $this->f->periode();
        $version = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($version);

        $beginnt = $this->f->fahrt($version, ['Betriebshof', 'Kannenstieg'], ['04:30:00', '04:42:00']);

        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'start',
            'to_trip_id' => $beginnt->id,
            'note' => 'Ausrücken',
        ])->assertCreated();

        $daten = $this->hole($this->haltestelleId('Betriebshof'), $periode);

        $this->assertSame('start', $daten['starting'][0]['decision']['kind']);
        $this->assertNull($daten['starting'][0]['decision']['partner']);
        $this->assertSame('Ausrücken', $daten['starting'][0]['decision']['note']);
        $this->assertSame(0, $daten['open_count']);
    }

    /**
     * Der Betriebshof hängt an der Entscheidung und muss mit ihr im Board stehen — sonst
     * müsste der Pflegende für die Angabe „ausgerückt aus Nord" in eine andere Ansicht.
     */
    public function test_a_terminal_decision_carries_its_depot(): void
    {
        $periode = $this->f->periode();
        $version = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($version);

        $beginnt = $this->f->fahrt($version, ['Betriebshof', 'Kannenstieg'], ['04:30:00', '04:42:00']);
        $hof = Depot::factory()->create(['name' => 'Nord-Hof', 'short_name' => 'Nord']);

        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'start',
            'to_trip_id' => $beginnt->id,
            'depot_id' => $hof->id,
        ])->assertCreated();

        $daten = $this->hole($this->haltestelleId('Betriebshof'), $periode);

        $this->assertSame($hof->id, $daten['starting'][0]['decision']['depot']['id']);
        $this->assertSame('Nord', $daten['starting'][0]['decision']['depot']['display']);
    }

    /** Ohne Hof ist die Angabe `null` — „noch offen", nicht „kein Hof". */
    public function test_a_terminal_decision_without_a_depot_reports_null(): void
    {
        $periode = $this->f->periode();
        $version = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($version);

        $beginnt = $this->f->fahrt($version, ['Betriebshof', 'Kannenstieg'], ['04:30:00', '04:42:00']);

        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'start',
            'to_trip_id' => $beginnt->id,
        ])->assertCreated();

        $daten = $this->hole($this->haltestelleId('Betriebshof'), $periode);

        $this->assertNull($daten['starting'][0]['decision']['depot']);
    }

    /**
     * Jeder Versionsstand trägt seine eigene Zahl offener Fahrten.
     *
     * Der Fall, der das nötig macht: Eine Nachtlinie hat im Mo-Fr-Strang regelmäßig eine
     * Eintagsversion — sie fährt in der Nacht auf einen Feiertag anders —, und daraus wird ein
     * Stand, der einen einzigen Tag umfasst. Wer im Hauptstand alles entschieden hat, liest
     * dort 0 und hält die Haltestelle für fertig, während die Auswahlliste noch etwas meldet:
     * Sie zählt über die ganze Periode. Ohne die Zahl je Stand bliebe der Widerspruch
     * unauflösbar, und man müsste jeden Stand einzeln durchklicken.
     */
    public function test_each_stand_reports_its_own_open_count(): void
    {
        $periode = $this->f->periode();

        // Hauptstand: gilt über den ganzen Zeitraum.
        $tag = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($tag, '2026-08-17', '2026-08-28');
        $entschieden = $this->f->fahrt($tag, ['Kannenstieg', 'Sudenburg'], ['06:00:00', '06:30:00']);

        // Ein einziger Tag mitten darin — die Eintagsversion.
        $nacht = $this->f->version('N1', FahrplanTyp::MoFrNormal, 2, $periode);
        $this->f->gueltigkeit($nacht, '2026-08-21', '2026-08-21');
        $this->f->fahrt($nacht, ['Herrenkrug', 'Sudenburg'], ['01:45:00', '02:15:00']);

        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'end',
            'from_trip_id' => $entschieden->id,
        ])->assertCreated();

        $daten = $this->hole($this->haltestelleId('Sudenburg'), $periode);

        $mitNacht = array_values(array_filter(
            $daten['stands'],
            static fn (array $st): bool => $st['line_count'] > 1,
        ));
        $ohneNacht = array_values(array_filter(
            $daten['stands'],
            static fn (array $st): bool => $st['line_count'] === 1,
        ));

        $this->assertNotEmpty($mitNacht, 'Der Eintagsstand muss eigens stehen.');
        $this->assertNotEmpty($ohneNacht);

        // Im Stand ohne die Nachtlinie ist alles entschieden …
        foreach ($ohneNacht as $stand) {
            $this->assertSame(0, $stand['open']['total']);
        }

        // … im Eintagsstand liegt die offene Fahrt, und sie ist als Tram ausgewiesen.
        $this->assertSame(1, $mitNacht[0]['open']['total']);
        $this->assertSame(1, $mitNacht[0]['open']['tram']);
    }

    /**
     * Eine Fahrt kann **je Versionsstand einen anderen Anschluss** tragen — und der Editor
     * zeigt je Stand den, der dort gilt.
     *
     * Der Fall entsteht, wenn eine *einzelne* Linie mitten in der Periode die Version wechselt:
     * Am City Carré tut das die 13, die 1, 2 und 5 nicht. Ein Anschluss 2 → 13, im ersten Stand
     * gesetzt, gilt nach dem Wechseltag nicht mehr — dort fährt dasselbe Fahrzeug auf die 13
     * der neuen Version weiter. Bis zum 23.09.2026 verhinderten das die Unique-Constraints:
     * Die 2er-Fahrt hatte ihren einen Anschluss verbraucht, und die Fahrt der neuen Version
     * stand offen daneben, ohne dass sich etwas tun ließ.
     */
    private function verknuepfe(ConsolidatedTrip $von, ConsolidatedTrip $nach): void
    {
        $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', [
            'kind' => 'link',
            'from_trip_id' => $von->id,
            'to_trip_id' => $nach->id,
        ])->assertCreated();
    }

    public function test_a_trip_can_carry_a_different_link_per_stand(): void
    {
        $periode = $this->f->periode();
        $gruppe = $this->f->haltestelle('Sudenburg');

        // Linie 2 gilt durchgehend, Linie 13 wechselt in der Mitte die Version.
        $zwei = $this->f->version('2', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($zwei, '2026-08-17', '2026-08-28');

        $alt = $this->f->version('13', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($alt, '2026-08-17', '2026-08-21');

        $neu = $this->f->version('13', FahrplanTyp::MoFrNormal, 2, $periode);
        $this->f->gueltigkeit($neu, '2026-08-24', '2026-08-28');

        $ankunft = $this->f->fahrt($zwei, ['Kannenstieg', 'Sudenburg'], ['06:00:00', '06:42:00']);
        $alteAbfahrt = $this->f->fahrt($alt, ['Sudenburg', 'Herrenkrug'], ['06:44:00', '07:20:00']);
        $neueAbfahrt = $this->f->fahrt($neu, ['Sudenburg', 'Herrenkrug'], ['06:44:00', '07:20:00'], 'sig-13-neu');

        $this->verknuepfe($ankunft, $alteAbfahrt);

        // **Der zweite Anschluss an derselben Fahrt** — früher ein 409, jetzt erlaubt, weil er
        // an anderen Tagen gilt.
        $this->verknuepfe($ankunft, $neueAbfahrt);

        $entscheidungen = [];

        foreach ($this->hole($gruppe->id, $periode)['stands'] as $stand) {
            $daten = $this->hole($gruppe->id, $periode, FahrplanTyp::MoFrNormal, $stand['index']);
            $fahrt = collect($daten['ending'])->firstWhere('id', $ankunft->id);

            $this->assertNotNull($fahrt, 'Die Ankunft gehört in jeden Stand, in dem ihre Version gilt.');
            $this->assertSame('link', $fahrt['decision']['kind']);

            $entscheidungen[$stand['index']] = $fahrt['decision']['partner']['id'];
        }

        // Je Stand ein anderer Partner — und in keinem der falsche.
        $this->assertContains($alteAbfahrt->id, $entscheidungen);
        $this->assertContains($neueAbfahrt->id, $entscheidungen);
        $this->assertSame(2, count(array_unique($entscheidungen)), 'Jeder Stand zeigt genau einen Partner.');
    }

    /**
     * Solange der zweite Anschluss **noch nicht** gesetzt ist, gilt die Fahrt im anderen Stand
     * als offen — nicht als entschieden. Nur so lässt sie sich dort überhaupt verknüpfen, und
     * nur so taucht die Arbeit in der Zählung auf.
     */
    public function test_a_trip_is_open_in_a_stand_where_its_link_does_not_apply(): void
    {
        $periode = $this->f->periode();
        $gruppe = $this->f->haltestelle('Sudenburg');

        $zwei = $this->f->version('2', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($zwei, '2026-08-17', '2026-08-28');

        $alt = $this->f->version('13', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($alt, '2026-08-17', '2026-08-21');

        $neu = $this->f->version('13', FahrplanTyp::MoFrNormal, 2, $periode);
        $this->f->gueltigkeit($neu, '2026-08-24', '2026-08-28');

        $ankunft = $this->f->fahrt($zwei, ['Kannenstieg', 'Sudenburg'], ['06:00:00', '06:42:00']);
        $alteAbfahrt = $this->f->fahrt($alt, ['Sudenburg', 'Herrenkrug'], ['06:44:00', '07:20:00']);
        $this->f->fahrt($neu, ['Sudenburg', 'Herrenkrug'], ['06:44:00', '07:20:00'], 'sig-13-neu');

        $this->verknuepfe($ankunft, $alteAbfahrt);

        $offen = 0;
        $entschieden = 0;

        foreach ($this->hole($gruppe->id, $periode)['stands'] as $stand) {
            $daten = $this->hole($gruppe->id, $periode, FahrplanTyp::MoFrNormal, $stand['index']);
            $fahrt = collect($daten['ending'])->firstWhere('id', $ankunft->id);

            $fahrt['decision'] === null ? $offen++ : $entschieden++;

            // Die Zahl am Stand folgt derselben Rechnung.
            $this->assertSame(
                $fahrt['decision'] === null,
                $stand['open']['total'] > 0,
                'Stand '.$stand['index'].': Zählung und Anzeige müssen dasselbe sagen.',
            );
        }

        $this->assertSame(1, $entschieden, 'Im Stand der alten 13er-Version gilt der Anschluss.');
        $this->assertSame(1, $offen, 'Im Stand der neuen gilt er nicht — dort ist die Fahrt offen.');
    }

    public function test_a_stop_without_trips_yields_empty_lists(): void
    {
        $periode = $this->f->periode();
        $gruppe = $this->f->haltestelle('Menschenleer');

        $daten = $this->hole($gruppe->id, $periode);

        $this->assertSame([], $daten['ending']);
        $this->assertSame([], $daten['starting']);
        $this->assertSame([], $daten['stands']);
        $this->assertNull($daten['stand']);
        $this->assertSame(0, $daten['open_count']);
    }

    /**
     * Der Fall, der "Periode + Fahrplantyp" mehrdeutig macht: Linie 1 wechselt mitten in der
     * Periode die Version, Linie 13 nicht. Daraus müssen zwei Stände werden.
     */
    public function test_a_version_change_splits_the_period_into_two_stands(): void
    {
        $periode = $this->f->periode();

        $einsAlt = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($einsAlt, '2026-08-17', '2026-09-05');

        $einsNeu = $this->f->version('1', FahrplanTyp::MoFrNormal, 2, $periode);
        $this->f->gueltigkeit($einsNeu, '2026-09-06', '2026-09-18');

        $dreizehn = $this->f->version('13', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($dreizehn, '2026-08-17', '2026-09-18');

        $this->f->fahrt($einsAlt, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);
        $this->f->fahrt($einsNeu, ['Kannenstieg', 'Sudenburg'], ['06:16:00', '06:50:00']);
        $this->f->fahrt($dreizehn, ['Sudenburg', 'Westerhüsen'], ['06:52:00', '07:24:00']);

        $daten = $this->hole($this->haltestelleId('Sudenburg'), $periode);

        $this->assertCount(2, $daten['stands']);

        // Die Intervallgrenzen liegen auf einem Samstag (05.09.) und einem Sonntag (06.09.).
        // Angezeigt wird der erste und letzte **Mo-Fr-Tag**, nicht die Rohgrenze — sonst
        // stuende dort ein Datum, an dem dieser Fahrplan gar nicht gilt.
        $this->assertSame('2026-08-17', $daten['stands'][0]['valid_from']);
        $this->assertSame('2026-09-04', $daten['stands'][0]['valid_to']);
        $this->assertSame([$einsAlt->id, $dreizehn->id], $daten['stands'][0]['line_version_ids']);

        $this->assertSame('2026-09-07', $daten['stands'][1]['valid_from']);
        $this->assertSame('2026-09-18', $daten['stands'][1]['valid_to']);
        $this->assertSame([$einsNeu->id, $dreizehn->id], $daten['stands'][1]['line_version_ids']);
    }

    /**
     * Angrenzende Abschnitte mit derselben Versionsmenge gehören zusammen — sonst zerfiele
     * die Auswahl in Abschnitte ohne fachlichen Unterschied.
     */
    public function test_adjacent_intervals_of_the_same_versions_are_merged(): void
    {
        $periode = $this->f->periode();
        $version = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($version, '2026-08-17', '2026-08-31');
        $this->f->gueltigkeit($version, '2026-09-01', '2026-09-18');

        $this->f->fahrt($version, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);

        $daten = $this->hole($this->haltestelleId('Sudenburg'), $periode);

        $this->assertCount(1, $daten['stands']);
        $this->assertSame('2026-08-17', $daten['stands'][0]['valid_from']);
        $this->assertSame('2026-09-18', $daten['stands'][0]['valid_to']);
    }

    /**
     * Kehrt eine Linie nach der Baustelle zum alten Fahrplan zurück, ist das **kein dritter
     * Stand**, sondern ein weiterer Zeitraum des ersten — genau wie eine Linien-Version über
     * ihren Fingerprint identifiziert ist und mehrere Intervalle trägt (FAHRPLANPERIODEN §5.4 a).
     *
     * Ohne diese Zusammenfassung zerfällt die Auswahl: Nachtlinien wechseln im `mo_fr`-Strang
     * wöchentlich die Version, was an Herrenkrug 16 Stände über vier Wochen ergab.
     */
    public function test_a_return_to_the_old_schedule_is_another_range_not_a_new_stand(): void
    {
        $periode = $this->f->periode();

        $normal = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($normal, '2026-08-01', '2026-08-15');
        $this->f->gueltigkeit($normal, '2026-09-01', '2026-09-18');

        $baustelle = $this->f->version('1', FahrplanTyp::MoFrNormal, 2, $periode);
        $this->f->gueltigkeit($baustelle, '2026-08-16', '2026-08-31');

        $this->f->fahrt($normal, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);
        $this->f->fahrt($baustelle, ['Kannenstieg', 'Sudenburg'], ['06:20:00', '06:58:00']);

        $daten = $this->hole($this->haltestelleId('Sudenburg'), $periode);

        $this->assertCount(2, $daten['stands']);

        $this->assertSame([$normal->id], $daten['stands'][0]['line_version_ids']);
        // 01.08. ist ein Samstag, 15.08. ebenfalls — beschnitten auf Mo 03.08. bis Fr 14.08.
        $this->assertSame(
            [
                ['valid_from' => '2026-08-03', 'valid_to' => '2026-08-14'],
                ['valid_from' => '2026-09-01', 'valid_to' => '2026-09-18'],
            ],
            $daten['stands'][0]['ranges'],
        );

        $this->assertSame([$baustelle->id], $daten['stands'][1]['line_version_ids']);
        $this->assertCount(1, $daten['stands'][1]['ranges']);
    }

    /**
     * Der Fall aus dem Realbestand: Eine Nachtlinie wechselt im `mo_fr`-Strang wöchentlich die
     * Version (montags ein anderer Fahrplan als Di–Fr, FAHRPLANPERIODEN §8), während die
     * Tageslinie durchgehend fährt. Das sind **zwei** Fahrplanstände, nicht sechzehn.
     */
    public function test_weekly_alternating_versions_collapse_into_two_stands(): void
    {
        $periode = $this->f->periode();

        $tagslinie = $this->f->version('6', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($tagslinie, '2026-08-17', '2026-09-11');

        // Montags-Fassung der Nachtlinie …
        $montags = $this->f->version('N1', FahrplanTyp::MoFrNormal, 1, $periode);
        foreach (['2026-08-17', '2026-08-24', '2026-08-31', '2026-09-07'] as $montag) {
            $this->f->gueltigkeit($montags, $montag, $montag);
        }

        // … und die Di–Fr-Fassung.
        $dienstagsBisFreitags = $this->f->version('N1', FahrplanTyp::MoFrNormal, 2, $periode);
        foreach ([['2026-08-18', '2026-08-21'], ['2026-08-25', '2026-08-28'], ['2026-09-01', '2026-09-04'], ['2026-09-08', '2026-09-11']] as [$von, $bis]) {
            $this->f->gueltigkeit($dienstagsBisFreitags, $von, $bis);
        }

        $this->f->fahrt($tagslinie, ['Alter Markt', 'Herrenkrug'], ['06:14:00', '06:48:00']);
        $this->f->fahrt($montags, ['Alter Markt', 'Herrenkrug'], ['24:14:00', '24:48:00']);
        $this->f->fahrt($dienstagsBisFreitags, ['Alter Markt', 'Herrenkrug'], ['24:20:00', '24:54:00']);

        $daten = $this->hole($this->haltestelleId('Herrenkrug'), $periode);

        $this->assertCount(2, $daten['stands']);

        // Vier Montage und vier Di–Fr-Blöcke — je ein Stand mit vier Zeiträumen.
        $this->assertCount(4, $daten['stands'][0]['ranges']);
        $this->assertCount(4, $daten['stands'][1]['ranges']);

        // Die Wochenenden tragen keinen Mo-Fr-Tag und dürfen gar nicht erst als Stand erscheinen.
        foreach ($daten['stands'] as $stand) {
            foreach ($stand['ranges'] as $zeitraum) {
                $this->assertNotSame('2026-08-22', $zeitraum['valid_from']);
            }
        }
    }

    /**
     * Ein Abschnitt, in den kein Tag des gewählten Typs fällt, ist kein Fahrplanstand, sondern
     * die Lücke dazwischen. Sonst erschienen im `mo_fr`-Strang die Wochenenden als Auswahl.
     */
    public function test_segments_without_a_day_of_the_selected_type_are_dropped(): void
    {
        $periode = $this->f->periode();

        $durchgehend = $this->f->version('6', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($durchgehend, '2026-08-17', '2026-08-30');

        // Nur an den Werktagen der ersten Woche — danach klafft ein reines Wochenende.
        $nurWoche = $this->f->version('N1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($nurWoche, '2026-08-17', '2026-08-21');

        $this->f->fahrt($durchgehend, ['Alter Markt', 'Herrenkrug'], ['06:14:00', '06:48:00']);
        $this->f->fahrt($nurWoche, ['Alter Markt', 'Herrenkrug'], ['24:14:00', '24:48:00']);

        $daten = $this->hole($this->haltestelleId('Herrenkrug'), $periode);

        // 22.–23.08. ist Sa/So: kein Mo-Fr-Tag, also kein eigener Stand. Bleiben zwei —
        // die Woche mit beiden Linien und die Folgewoche mit nur einer.
        $this->assertCount(2, $daten['stands']);
        $this->assertSame(2, $daten['stands'][0]['line_count']);
        $this->assertSame(1, $daten['stands'][1]['line_count']);
        $this->assertSame('2026-08-24', $daten['stands'][1]['ranges'][0]['valid_from']);
    }

    public function test_only_the_selected_stand_contributes_trips(): void
    {
        $periode = $this->f->periode();

        $alt = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($alt, '2026-08-17', '2026-09-05');

        $neu = $this->f->version('1', FahrplanTyp::MoFrNormal, 2, $periode);
        $this->f->gueltigkeit($neu, '2026-09-06', '2026-09-18');

        $alteFahrt = $this->f->fahrt($alt, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);
        $neueFahrt = $this->f->fahrt($neu, ['Kannenstieg', 'Sudenburg'], ['06:16:00', '06:50:00']);

        $ersterStand = $this->hole($this->haltestelleId('Sudenburg'), $periode, stand: 0);
        $zweiterStand = $this->hole($this->haltestelleId('Sudenburg'), $periode, stand: 1);

        $this->assertSame([$alteFahrt->id], array_column($ersterStand['ending'], 'id'));
        $this->assertSame([$neueFahrt->id], array_column($zweiterStand['ending'], 'id'));
    }

    public function test_unknown_stand_is_rejected(): void
    {
        $periode = $this->f->periode();
        $version = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($version);
        $this->f->fahrt($version, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);

        $gruppe = $this->haltestelleId('Sudenburg');

        $this->withToken($this->token())
            ->getJson("/api/v1/admin/stop-links?stop_group={$gruppe}&period={$periode->id}&day_type=mo_fr&stand=99")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_other_day_types_are_not_mixed_in(): void
    {
        $periode = $this->f->periode();

        $werktag = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($werktag);
        $werktagsfahrt = $this->f->fahrt($werktag, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);

        $sonntag = $this->f->version('1', FahrplanTyp::SoFeiertag, 1, $periode);
        $this->f->gueltigkeit($sonntag);
        $this->f->fahrt($sonntag, ['Kannenstieg', 'Sudenburg'], ['08:14:00', '08:48:00']);

        $daten = $this->hole($this->haltestelleId('Sudenburg'), $periode);

        $this->assertSame([$werktagsfahrt->id], array_column($daten['ending'], 'id'));
    }

    public function test_other_periods_are_not_mixed_in(): void
    {
        $alt = SchedulePeriod::factory()->create(['valid_from' => '2026-01-01']);
        $neu = SchedulePeriod::factory()->create(['valid_from' => '2026-08-01']);

        $alteVersion = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $alt);
        $this->f->gueltigkeit($alteVersion, '2026-01-01', '2026-07-31');
        $this->f->fahrt($alteVersion, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);

        $neueVersion = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $neu);
        $this->f->gueltigkeit($neueVersion, '2026-08-01', '2026-09-18');
        $neueFahrt = $this->f->fahrt($neueVersion, ['Kannenstieg', 'Sudenburg'], ['06:16:00', '06:50:00']);

        $daten = $this->hole($this->haltestelleId('Sudenburg'), $neu);

        $this->assertSame([$neueFahrt->id], array_column($daten['ending'], 'id'));
    }
}
