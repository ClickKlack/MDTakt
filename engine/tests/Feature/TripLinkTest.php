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
use Illuminate\Testing\TestResponse;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Umlauf-Entscheidungen: Anschluss zweier Fahrten, Ausrücken, Einrücken (KURSE §4).
 */
final class TripLinkTest extends TestCase
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
     * Eine Version mit beobachteter Gültigkeit — ohne sie überschneidet sich keine
     * Verknüpfung mit irgendetwas.
     */
    private function version(
        string $linie = '1',
        FahrplanTyp $typ = FahrplanTyp::MoFrNormal,
        int $versionNo = 1,
        ?SchedulePeriod $periode = null,
    ): LineVersion {
        $version = $this->f->version($linie, $typ, $versionNo, $periode);
        $this->f->gueltigkeit($version);

        return $version;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function anlegen(array $body): TestResponse
    {
        return $this->withToken($this->token())->postJson('/api/v1/admin/trip-links', $body);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/admin/trip-links', ['kind' => 'link'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_delete_requires_authentication(): void
    {
        $this->deleteJson('/api/v1/admin/trip-links/1')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_two_trips_meeting_at_a_stop_can_be_linked(): void
    {
        $version = $this->version();
        $hin = $this->f->fahrt($version, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);
        $zurueck = $this->f->fahrt($version, ['Sudenburg', 'Kannenstieg'], ['06:52:00', '07:26:00']);

        $daten = $this->anlegen([
            'kind' => 'link',
            'from_trip_id' => $hin->id,
            'to_trip_id' => $zurueck->id,
        ])->assertCreated()->json('data');

        $this->assertSame('link', $daten['kind']);
        $this->assertSame($hin->last_stop_id, $daten['stop_id']);
        // 06:48 → 06:52 sind vier Minuten.
        $this->assertSame(240, $daten['turnaround_seconds']);
        $this->assertSame([], $daten['warnings']);

        $this->assertDatabaseHas('trip_links', [
            'from_trip_id' => $hin->id,
            'to_trip_id' => $zurueck->id,
            'kind' => 'link',
        ]);
    }

    public function test_a_chain_may_begin_without_a_predecessor(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['Betriebshof', 'Kannenstieg'], ['04:30:00', '04:42:00']);

        $daten = $this->anlegen([
            'kind' => 'start',
            'to_trip_id' => $fahrt->id,
            'note' => 'Ausrücken Betriebshof',
        ])->assertCreated()->json('data');

        $this->assertSame('start', $daten['kind']);
        $this->assertNull($daten['from_trip']);
        $this->assertSame('Ausrücken Betriebshof', $daten['note']);
        $this->assertSame($fahrt->first_stop_id, $daten['stop_id']);
    }

    public function test_a_chain_may_end_without_a_successor(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['Kannenstieg', 'Betriebshof'], ['23:12:00', '23:30:00']);

        $daten = $this->anlegen(['kind' => 'end', 'from_trip_id' => $fahrt->id])
            ->assertCreated()->json('data');

        $this->assertSame('end', $daten['kind']);
        $this->assertNull($daten['to_trip']);
        $this->assertSame($fahrt->last_stop_id, $daten['stop_id']);
    }

    // ---------------------------------------------------------------- Betriebshof (KURSE §3.2)

    /**
     * Der Hof ist **freiwillig**. An einer Endstelle steht er oft nicht fest, und eine erzwungene
     * Angabe wäre dort geraten — Geratenes ist schlimmer als eine offene Angabe.
     */
    public function test_a_terminal_decision_may_be_marked_without_a_depot(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['Kannenstieg', 'Sudenburg'], ['23:12:00', '23:30:00']);

        $daten = $this->anlegen(['kind' => 'end', 'from_trip_id' => $fahrt->id])
            ->assertCreated()->json('data');

        $this->assertNull($daten['depot']);
    }

    public function test_a_depot_can_be_set_when_the_decision_is_made(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['Betriebshof', 'Kannenstieg'], ['04:30:00', '04:42:00']);
        $hof = Depot::factory()->create(['name' => 'Betriebshof Nord X', 'short_name' => 'Nord']);

        $daten = $this->anlegen(['kind' => 'start', 'to_trip_id' => $fahrt->id, 'depot_id' => $hof->id])
            ->assertCreated()->json('data');

        $this->assertSame($hof->id, $daten['depot']['id']);
        $this->assertSame('Nord', $daten['depot']['display']);
        $this->assertDatabaseHas('trip_links', ['to_trip_id' => $fahrt->id, 'depot_id' => $hof->id]);
    }

    // ------------------------------------------------ Automatische Zuordnung über die Haltestelle

    /**
     * Ordnet ein Hof die Haltestelle zu, ist er beim Markieren gleich gesetzt.
     *
     * Das ist der Regelfall in Westerhüsen: Die Ausrückfahrten beginnen an der *Schleswiger
     * Straße*, nicht am Hof selbst — der Weg dorthin steht im Fahrplan gar nicht. Ihn je Fahrt
     * von Hand nachzuklicken wäre Arbeit, die aus der Zuordnung schon folgt.
     */
    public function test_the_depot_is_set_automatically_from_the_stop_group(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['Schleswiger Straße', 'Kannenstieg'], ['04:30:00', '05:05:00']);

        $halt = $this->f->halt('Schleswiger Straße');
        $gruppe = $this->f->haltestelle('Westerhüsen');
        $this->f->zurHaltestelle($halt, $gruppe);

        $hof = Depot::factory()->forModes(['tram'])->create(['name' => 'West-Hof']);
        $hof->stopGroups()->attach($gruppe->id);

        $daten = $this->anlegen(['kind' => 'start', 'to_trip_id' => $fahrt->id])
            ->assertCreated()->json('data');

        $this->assertSame($hof->id, $daten['depot']['id']);
    }

    /** Dasselbe am anderen Ende der Kette: Wer hier endet, fährt in diesen Hof. */
    public function test_the_depot_is_set_automatically_when_a_chain_ends(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['Kannenstieg', 'Schleswiger Straße'], ['23:10:00', '23:45:00']);

        $halt = $this->f->halt('Schleswiger Straße');
        $gruppe = $this->f->haltestelle('Westerhüsen');
        $this->f->zurHaltestelle($halt, $gruppe);

        $hof = Depot::factory()->forModes(['tram'])->create(['name' => 'West-Hof']);
        $hof->stopGroups()->attach($gruppe->id);

        $daten = $this->anlegen(['kind' => 'end', 'from_trip_id' => $fahrt->id])
            ->assertCreated()->json('data');

        $this->assertSame($hof->id, $daten['depot']['id']);
    }

    /** Ein Tram-Hof an dieser Haltestelle hilft einem Bus nicht — dann bleibt der Hof offen. */
    public function test_the_automatic_depot_respects_the_mode(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['Schleswiger Straße', 'Kannenstieg'], ['04:30:00', '05:05:00']);
        $fahrt->update(['route_type' => 3]);

        $halt = $this->f->halt('Schleswiger Straße');
        $gruppe = $this->f->haltestelle('Westerhüsen');
        $this->f->zurHaltestelle($halt, $gruppe);

        $nurTram = Depot::factory()->forModes(['tram'])->create(['name' => 'Tram-Hof']);
        $nurTram->stopGroups()->attach($gruppe->id);

        $daten = $this->anlegen(['kind' => 'start', 'to_trip_id' => $fahrt->id])
            ->assertCreated()->json('data');

        $this->assertNull($daten['depot']);
    }

    /**
     * **Geraten wird nicht.** Passen zwei Höfe, bleibt die Angabe offen: Sie ist als „noch
     * offen" lesbar, ein falscher Hof dagegen stünde als Tatsache in den Daten.
     */
    public function test_an_ambiguous_stop_group_leaves_the_depot_open(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['Schleswiger Straße', 'Kannenstieg'], ['04:30:00', '05:05:00']);

        $halt = $this->f->halt('Schleswiger Straße');
        $gruppe = $this->f->haltestelle('Westerhüsen');
        $this->f->zurHaltestelle($halt, $gruppe);

        foreach (['Hof A', 'Hof B'] as $name) {
            Depot::factory()->create(['name' => $name])->stopGroups()->attach($gruppe->id);
        }

        $daten = $this->anlegen(['kind' => 'start', 'to_trip_id' => $fahrt->id])
            ->assertCreated()->json('data');

        $this->assertNull($daten['depot']);
    }

    /** Ein stillgelegter Hof wird auch nicht mehr automatisch vergeben. */
    public function test_a_retired_depot_is_not_suggested(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['Schleswiger Straße', 'Kannenstieg'], ['04:30:00', '05:05:00']);

        $halt = $this->f->halt('Schleswiger Straße');
        $gruppe = $this->f->haltestelle('Westerhüsen');
        $this->f->zurHaltestelle($halt, $gruppe);

        Depot::factory()->inactive()->create(['name' => 'Alter Hof'])->stopGroups()->attach($gruppe->id);

        $this->anlegen(['kind' => 'start', 'to_trip_id' => $fahrt->id])
            ->assertCreated()
            ->assertJsonPath('data.depot', null);
    }

    /**
     * Ein ausdrückliches `depot_id: null` heißt „bewusst offen" und darf die Automatik **nicht**
     * auslösen. Ohne diese Unterscheidung liesse sich ein automatisch gesetzter Hof beim Neu-
     * Markieren nicht abwählen.
     */
    public function test_an_explicit_null_suppresses_the_automatic_depot(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['Schleswiger Straße', 'Kannenstieg'], ['04:30:00', '05:05:00']);

        $halt = $this->f->halt('Schleswiger Straße');
        $gruppe = $this->f->haltestelle('Westerhüsen');
        $this->f->zurHaltestelle($halt, $gruppe);

        Depot::factory()->create(['name' => 'West-Hof'])->stopGroups()->attach($gruppe->id);

        $this->anlegen(['kind' => 'start', 'to_trip_id' => $fahrt->id, 'depot_id' => null])
            ->assertCreated()
            ->assertJsonPath('data.depot', null);
    }

    /** Ein Anschluss bleibt unberührt — er führt zu keinem Hof, auch nicht automatisch. */
    public function test_a_link_gets_no_automatic_depot(): void
    {
        $version = $this->version();
        $hin = $this->f->fahrt($version, ['Kannenstieg', 'Schleswiger Straße'], ['06:00:00', '06:30:00']);
        $zurueck = $this->f->fahrt($version, ['Schleswiger Straße', 'Kannenstieg'], ['06:36:00', '07:06:00']);

        $halt = $this->f->halt('Schleswiger Straße');
        $gruppe = $this->f->haltestelle('Westerhüsen');
        $this->f->zurHaltestelle($halt, $gruppe);

        Depot::factory()->create(['name' => 'West-Hof'])->stopGroups()->attach($gruppe->id);

        $this->anlegen(['kind' => 'link', 'from_trip_id' => $hin->id, 'to_trip_id' => $zurueck->id])
            ->assertCreated()
            ->assertJsonPath('data.depot', null);
    }

    /** Der übliche Weg: erst markieren, den Hof nachtragen, sobald er feststeht. */
    public function test_a_depot_can_be_added_and_removed_afterwards(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['Kannenstieg', 'Sudenburg'], ['23:12:00', '23:30:00']);
        $hof = Depot::factory()->create();

        $id = $this->anlegen(['kind' => 'end', 'from_trip_id' => $fahrt->id])->json('data.id');

        $this->withToken($this->token())
            ->putJson('/api/v1/admin/trip-links/'.$id.'/depot', ['depot_id' => $hof->id])
            ->assertOk()
            ->assertJsonPath('data.depot.id', $hof->id);

        // Zurück auf „noch offen" — das ist kein Rückschritt, sondern die ehrliche Angabe.
        $this->withToken($this->token())
            ->putJson('/api/v1/admin/trip-links/'.$id.'/depot', ['depot_id' => null])
            ->assertOk()
            ->assertJsonPath('data.depot', null);

        $this->assertDatabaseHas('trip_links', ['id' => $id, 'depot_id' => null]);
    }

    /**
     * Aus- und Einrückhof sind **nicht** zwangsläufig derselbe: Ein Fahrzeug rückt morgens aus
     * Nord aus und abends in Westerhüsen ein, wenn der Umlauf es dorthin trägt.
     */
    public function test_the_outbound_and_inbound_depot_may_differ(): void
    {
        $version = $this->version();
        $morgens = $this->f->fahrt($version, ['Nord', 'Kannenstieg'], ['04:30:00', '04:42:00']);
        $abends = $this->f->fahrt($version, ['Kannenstieg', 'Westerhüsen'], ['23:12:00', '23:40:00']);

        $nord = Depot::factory()->create(['name' => 'Nord-Hof']);
        $west = Depot::factory()->create(['name' => 'West-Hof']);

        $this->anlegen(['kind' => 'start', 'to_trip_id' => $morgens->id, 'depot_id' => $nord->id])
            ->assertCreated()
            ->assertJsonPath('data.depot.id', $nord->id);

        $this->anlegen(['kind' => 'end', 'from_trip_id' => $abends->id, 'depot_id' => $west->id])
            ->assertCreated()
            ->assertJsonPath('data.depot.id', $west->id);
    }

    /** Ein Anschluss führt zu keinem Hof — das Fahrzeug fährt weiter, es rückt nicht ein. */
    public function test_a_link_rejects_a_depot(): void
    {
        $version = $this->version();
        $hin = $this->f->fahrt($version, ['Kannenstieg', 'Sudenburg'], ['06:00:00', '06:30:00']);
        $zurueck = $this->f->fahrt($version, ['Sudenburg', 'Kannenstieg'], ['06:36:00', '07:06:00']);
        $hof = Depot::factory()->create();

        $this->anlegen([
            'kind' => 'link',
            'from_trip_id' => $hin->id,
            'to_trip_id' => $zurueck->id,
            'depot_id' => $hof->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('trip_links', 0);
    }

    /** Ein Tram-Hof nimmt keinen Bus auf — wer das zulässt, schreibt einen Umlauf fest, den es nicht gibt. */
    public function test_a_depot_that_does_not_take_this_mode_is_rejected(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['Betriebshof', 'Kannenstieg'], ['04:30:00', '04:42:00']);
        $fahrt->update(['route_type' => 3]);

        $nurTram = Depot::factory()->forModes(['tram'])->create(['name' => 'Tram-Hof']);

        $this->anlegen(['kind' => 'start', 'to_trip_id' => $fahrt->id, 'depot_id' => $nurTram->id])
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'Der Betriebshof „Tram-Hof" nimmt dieses Verkehrsmittel nicht auf.');
    }

    /** Stillgelegt heißt: an alten Entscheidungen lesbar, aber nichts Neues mehr. */
    public function test_a_retired_depot_takes_no_new_decisions(): void
    {
        $version = $this->version();
        $fahrt = $this->f->fahrt($version, ['Betriebshof', 'Kannenstieg'], ['04:30:00', '04:42:00']);
        $alt = Depot::factory()->inactive()->create(['name' => 'Alter Hof']);

        $this->anlegen(['kind' => 'start', 'to_trip_id' => $fahrt->id, 'depot_id' => $alt->id])
            ->assertStatus(422)
            ->assertJsonPath(
                'error.message',
                'Der Betriebshof „Alter Hof" ist stillgelegt und nimmt keine neuen Fahrten mehr auf.',
            );
    }

    public function test_setting_a_depot_on_a_link_is_rejected(): void
    {
        $version = $this->version();
        $hin = $this->f->fahrt($version, ['Kannenstieg', 'Sudenburg'], ['06:00:00', '06:30:00']);
        $zurueck = $this->f->fahrt($version, ['Sudenburg', 'Kannenstieg'], ['06:36:00', '07:06:00']);
        $hof = Depot::factory()->create();

        $id = $this->anlegen(['kind' => 'link', 'from_trip_id' => $hin->id, 'to_trip_id' => $zurueck->id])
            ->json('data.id');

        $this->withToken($this->token())
            ->putJson('/api/v1/admin/trip-links/'.$id.'/depot', ['depot_id' => $hof->id])
            ->assertStatus(422);
    }

    /**
     * Der Fall aus KURSE §2 K1: Eine 1 wird in Sudenburg zur 13. Das ist der Normalfall
     * einer Fahrzeugkette und muss erlaubt sein — nur sichtbar gemacht.
     */
    public function test_a_chain_may_change_lines(): void
    {
        $periode = $this->f->periode();
        $eins = $this->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $dreizehn = $this->version('13', FahrplanTyp::MoFrNormal, 1, $periode);

        $aufEins = $this->f->fahrt($eins, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);
        $aufDreizehn = $this->f->fahrt($dreizehn, ['Sudenburg', 'Westerhüsen'], ['06:52:00', '07:24:00']);

        $daten = $this->anlegen([
            'kind' => 'link',
            'from_trip_id' => $aufEins->id,
            'to_trip_id' => $aufDreizehn->id,
        ])->assertCreated()->json('data');

        $this->assertSame(240, $daten['turnaround_seconds']);
        $this->assertSame(['line_change'], array_column($daten['warnings'], 'code'));
    }

    /**
     * Mitternacht-Grenzfall: GTFS-Zeiten gehören zum Betriebstag, nicht zur Uhr. 24:50 → 25:10
     * sind zwanzig Minuten Wendezeit, kein Rückwärtssprung.
     */
    public function test_a_link_across_midnight_is_valid(): void
    {
        $version = $this->version('N1');
        $spaet = $this->f->fahrt($version, ['Alter Markt', 'Reform'], ['24:20:00', '24:50:00']);
        $noch_spaeter = $this->f->fahrt($version, ['Reform', 'Alter Markt'], ['25:10:00', '25:40:00']);

        $daten = $this->anlegen([
            'kind' => 'link',
            'from_trip_id' => $spaet->id,
            'to_trip_id' => $noch_spaeter->id,
        ])->assertCreated()->json('data');

        $this->assertSame(1200, $daten['turnaround_seconds']);
        $this->assertSame([], $daten['warnings']);
    }

    /**
     * Der Fall, wie er im Realbestand tatsächlich steht: Der gtfs.de-Feed notiert **keine**
     * Zeiten jenseits 24:00, eine Nachtfahrt um 00:19 steht als `00:19:00`. Nach der Uhr
     * gerechnet wäre die Wendezeit negativ — damit wäre jeder Nachtlinien-Anschluss über
     * Mitternacht abgewiesen worden.
     *
     * Gerechnet wird deshalb entlang des Betriebstags: Auf der N1 liegt 00:19 hinter 23:20.
     */
    public function test_a_night_line_connects_across_midnight_without_24h_notation(): void
    {
        $version = $this->version('N1');
        $abends = $this->f->fahrt($version, ['Alter Markt', 'Reform'], ['22:49:00', '23:20:00']);
        $nachts = $this->f->fahrt($version, ['Reform', 'Alter Markt'], ['00:19:00', '00:50:00']);

        $daten = $this->anlegen([
            'kind' => 'link',
            'from_trip_id' => $abends->id,
            'to_trip_id' => $nachts->id,
        ])->assertCreated()->json('data');

        // 23:20 -> 00:19 sind 59 Minuten, kein Ruecksprung um 23 Stunden.
        $this->assertSame(3540, $daten['turnaround_seconds']);
        $this->assertSame([], $daten['warnings']);
    }

    /**
     * Die Gegenprobe: Auf einer **Taglinie** liegt 00:19 vor 23:20 am selben Betriebstag —
     * dort ist der Rücksprung echt und bleibt ein Fehler.
     */
    public function test_a_day_line_still_rejects_a_backwards_link(): void
    {
        $version = $this->version('1');
        $spaet = $this->f->fahrt($version, ['A', 'B'], ['22:49:00', '23:20:00']);
        $frueh = $this->f->fahrt($version, ['B', 'A'], ['06:19:00', '06:50:00']);

        $this->anlegen(['kind' => 'link', 'from_trip_id' => $spaet->id, 'to_trip_id' => $frueh->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    /**
     * Die GTFS-Spezifikation erlaubt `7:00:00` neben `07:00:00`. Lexikalisch stünde das
     * hinter `23:50:00` — die Wendezeit wäre grotesk falsch.
     */
    public function test_turnaround_is_computed_from_seconds_not_from_string_order(): void
    {
        $version = $this->version();
        $ende = $this->f->fahrt($version, ['A', 'B'], ['6:30:00', '6:58:00']);
        $start = $this->f->fahrt($version, ['B', 'A'], ['7:02:00', '7:30:00']);

        $daten = $this->anlegen([
            'kind' => 'link',
            'from_trip_id' => $ende->id,
            'to_trip_id' => $start->id,
        ])->assertCreated()->json('data');

        $this->assertSame(240, $daten['turnaround_seconds']);
    }

    public function test_short_turnaround_is_a_warning_not_an_error(): void
    {
        $version = $this->version();
        $hin = $this->f->fahrt($version, ['A', 'B'], ['06:14:00', '06:48:00']);
        $zurueck = $this->f->fahrt($version, ['B', 'A'], ['06:49:00', '07:20:00']);

        $daten = $this->anlegen([
            'kind' => 'link',
            'from_trip_id' => $hin->id,
            'to_trip_id' => $zurueck->id,
        ])->assertCreated()->json('data');

        $this->assertSame(60, $daten['turnaround_seconds']);
        $this->assertSame(['short_turnaround'], array_column($daten['warnings'], 'code'));
    }

    public function test_negative_turnaround_is_rejected(): void
    {
        $version = $this->version();
        $hin = $this->f->fahrt($version, ['A', 'B'], ['06:14:00', '06:48:00']);
        $zurueck = $this->f->fahrt($version, ['B', 'A'], ['06:30:00', '07:00:00']);

        $this->anlegen(['kind' => 'link', 'from_trip_id' => $hin->id, 'to_trip_id' => $zurueck->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    /**
     * Der Linienwechsel ist erlaubt, der **Gattungswechsel** nicht: Ein Fahrzeug wird nie vom
     * Tram zum Bus. Beides auseinanderzuhalten ist wesentlich, weil dieselbe Linie beides sein
     * kann — N2 liegt zeitweise als Tram und als Bus vor (Schienenersatzverkehr).
     */
    public function test_a_tram_cannot_continue_as_a_bus(): void
    {
        $periode = $this->f->periode();
        $tram = $this->version('N2', FahrplanTyp::MoFrNormal, 1, $periode);
        $bus = $this->version('N2', FahrplanTyp::MoFrNormal, 2, $periode);

        $tramFahrt = $this->f->fahrt($tram, ['A', 'B'], ['06:14:00', '06:48:00']);
        $busFahrt = $this->f->fahrt($bus, ['B', 'C'], ['06:52:00', '07:20:00']);

        ConsolidatedTrip::query()->whereKey($tramFahrt->id)->update(['route_type' => 0]);
        ConsolidatedTrip::query()->whereKey($busFahrt->id)->update(['route_type' => 3]);

        $this->anlegen(['kind' => 'link', 'from_trip_id' => $tramFahrt->id, 'to_trip_id' => $busFahrt->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_two_trams_of_different_lines_may_be_linked(): void
    {
        $periode = $this->f->periode();
        $eins = $this->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $dreizehn = $this->version('13', FahrplanTyp::MoFrNormal, 1, $periode);

        $a = $this->f->fahrt($eins, ['Kannenstieg', 'Sudenburg'], ['06:14:00', '06:48:00']);
        $b = $this->f->fahrt($dreizehn, ['Sudenburg', 'Westerhüsen'], ['06:52:00', '07:24:00']);

        ConsolidatedTrip::query()->whereIn('id', [$a->id, $b->id])->update(['route_type' => 0]);

        $this->anlegen(['kind' => 'link', 'from_trip_id' => $a->id, 'to_trip_id' => $b->id])
            ->assertCreated();
    }

    public function test_stop_mismatch_is_rejected(): void
    {
        $version = $this->version();
        $hin = $this->f->fahrt($version, ['A', 'B'], ['06:14:00', '06:48:00']);
        // Beginnt an C, nicht an B — hier kann kein Fahrzeug weiterfahren.
        $fremd = $this->f->fahrt($version, ['C', 'D'], ['06:52:00', '07:20:00']);

        $this->anlegen(['kind' => 'link', 'from_trip_id' => $hin->id, 'to_trip_id' => $fremd->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_different_day_types_are_rejected(): void
    {
        $periode = $this->f->periode();
        $werktag = $this->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $sonntag = $this->version('1', FahrplanTyp::SoFeiertag, 1, $periode);

        $hin = $this->f->fahrt($werktag, ['A', 'B'], ['06:14:00', '06:48:00']);
        $zurueck = $this->f->fahrt($sonntag, ['B', 'A'], ['06:52:00', '07:20:00']);

        $this->anlegen(['kind' => 'link', 'from_trip_id' => $hin->id, 'to_trip_id' => $zurueck->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_different_periods_are_rejected(): void
    {
        $alt = SchedulePeriod::factory()->create(['valid_from' => '2026-01-01']);
        $neu = SchedulePeriod::factory()->create(['valid_from' => '2026-08-01']);

        $alteVersion = $this->version('1', FahrplanTyp::MoFrNormal, 1, $alt);
        $neueVersion = $this->version('1', FahrplanTyp::MoFrNormal, 1, $neu);

        $hin = $this->f->fahrt($alteVersion, ['A', 'B'], ['06:14:00', '06:48:00']);
        $zurueck = $this->f->fahrt($neueVersion, ['B', 'A'], ['06:52:00', '07:20:00']);

        $this->anlegen(['kind' => 'link', 'from_trip_id' => $hin->id, 'to_trip_id' => $zurueck->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    /**
     * Zwei Versionen, die an keinem Tag gleichzeitig gelten, können keinen Anschluss bilden —
     * die Fahrpläne standen nie zusammen in Kraft.
     */
    public function test_versions_without_overlapping_validity_are_rejected(): void
    {
        $periode = $this->f->periode();

        $frueh = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($frueh, '2026-08-01', '2026-08-15');

        $spaet = $this->f->version('13', FahrplanTyp::MoFrNormal, 1, $periode);
        $this->f->gueltigkeit($spaet, '2026-09-01', '2026-09-15');

        $hin = $this->f->fahrt($frueh, ['A', 'B'], ['06:14:00', '06:48:00']);
        $zurueck = $this->f->fahrt($spaet, ['B', 'C'], ['06:52:00', '07:20:00']);

        $this->anlegen(['kind' => 'link', 'from_trip_id' => $hin->id, 'to_trip_id' => $zurueck->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_self_link_is_rejected(): void
    {
        $version = $this->version();
        // Wendeschleife: Start- und Zielhalt sind derselbe Punkt.
        $rundfahrt = $this->f->fahrt($version, ['A', 'B', 'A'], ['06:00:00', '06:20:00', '06:40:00']);

        $this->anlegen([
            'kind' => 'link',
            'from_trip_id' => $rundfahrt->id,
            'to_trip_id' => $rundfahrt->id,
        ])->assertStatus(422)->assertJsonPath('error.code', 422);
    }

    /**
     * Ein Umlauf ist eine Folge, kein Kreis. Ohne diese Prüfung wäre die Kette nicht
     * mehr auslesbar.
     */
    public function test_a_cycle_is_rejected(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);

        $this->anlegen(['kind' => 'link', 'from_trip_id' => $a->id, 'to_trip_id' => $b->id])
            ->assertCreated();

        // b → a würde den Ring schließen.
        $this->anlegen(['kind' => 'link', 'from_trip_id' => $b->id, 'to_trip_id' => $a->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_an_already_decided_trip_yields_409(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);
        $c = $this->f->fahrt($version, ['B', 'C'], ['06:40:00', '07:10:00']);

        $this->anlegen(['kind' => 'link', 'from_trip_id' => $a->id, 'to_trip_id' => $b->id])
            ->assertCreated();

        $this->anlegen(['kind' => 'link', 'from_trip_id' => $a->id, 'to_trip_id' => $c->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 409);
    }

    public function test_start_with_a_predecessor_is_rejected(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);

        $this->anlegen(['kind' => 'start', 'from_trip_id' => $a->id, 'to_trip_id' => $b->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_link_without_both_trips_is_rejected(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);

        $this->anlegen(['kind' => 'link', 'from_trip_id' => $a->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_unknown_trip_is_rejected(): void
    {
        $this->anlegen(['kind' => 'end', 'from_trip_id' => 999999])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_a_decision_can_be_removed_and_set_again(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);

        $id = $this->anlegen(['kind' => 'link', 'from_trip_id' => $a->id, 'to_trip_id' => $b->id])
            ->assertCreated()->json('data.id');

        $this->withToken($this->token())
            ->deleteJson("/api/v1/admin/trip-links/{$id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('trip_links', ['id' => $id]);

        // Nach dem Lösen ist dieselbe Verknüpfung wieder setzbar — sonst wäre eine
        // Fehlentscheidung endgültig.
        $this->anlegen(['kind' => 'link', 'from_trip_id' => $a->id, 'to_trip_id' => $b->id])
            ->assertCreated();
    }

    public function test_deleting_an_unknown_link_yields_404(): void
    {
        $this->withToken($this->token())
            ->deleteJson('/api/v1/admin/trip-links/999999')
            ->assertStatus(404);
    }

    /**
     * Die Pflege hängt an `consolidated_trips.id`. Verschwindet eine Fahrt, darf keine
     * verwaiste Entscheidung zurückbleiben.
     */
    public function test_decisions_vanish_with_their_trip(): void
    {
        $version = $this->version();
        $a = $this->f->fahrt($version, ['A', 'B'], ['06:00:00', '06:30:00']);
        $b = $this->f->fahrt($version, ['B', 'A'], ['06:35:00', '07:05:00']);

        $id = $this->anlegen(['kind' => 'link', 'from_trip_id' => $a->id, 'to_trip_id' => $b->id])
            ->assertCreated()->json('data.id');

        ConsolidatedTrip::query()->whereKey($a->id)->delete();

        $this->assertNull(TripLink::query()->find($id));
    }
}
