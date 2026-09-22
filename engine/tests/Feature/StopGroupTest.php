<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Models\ConsolidatedStop;
use App\Models\ConsolidatedStopVersion;
use App\Models\SchedulePeriod;
use App\Models\StopGroup;
use App\Models\StopGroupMember;
use App\Models\TripLink;
use App\Models\User;
use App\Services\StopGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Haltestellen als Betriebspunkte: automatische Gruppierung über den Namen, Pflege von Hand
 * für den Rest (KURSE §3.1).
 */
final class StopGroupTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    private function halt(string $name, float $lat = 52.13, float $lon = 11.63): ConsolidatedStop
    {
        $halt = ConsolidatedStop::factory()->create([
            'name_key' => mb_strtolower(preg_replace('/[^a-z]+/i', '', $name) ?? $name),
            'anchor_lat' => $lat,
            'anchor_lon' => $lon,
        ]);

        ConsolidatedStopVersion::factory()->create([
            'consolidated_stop_id' => $halt->id,
            'name' => $name,
            'lat' => $lat,
            'lon' => $lon,
        ]);

        return $halt;
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/stop-groups')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    /**
     * Der Regelfall: Die Richtungs-Bahnsteige einer Endstelle heißen gleich. Am Realbestand
     * deckt das 49 von 64 einseitigen Endstellen ab.
     */
    public function test_stops_with_the_same_name_form_one_stop_group(): void
    {
        $this->halt('Herrenkrug', 52.1514, 11.6793);
        $this->halt('Herrenkrug', 52.1508, 11.6789);

        app(StopGroupService::class)->sync();

        $this->assertSame(1, StopGroup::query()->count());
        $this->assertSame(2, StopGroupMember::query()->count());
    }

    public function test_stops_with_different_names_stay_apart(): void
    {
        $this->halt('Rothensee');
        $this->halt('Rothensee (Schleife)');

        app(StopGroupService::class)->sync();

        $this->assertSame(2, StopGroup::query()->count());
    }

    /**
     * Genau der Fall, den die Automatik nicht lösen kann: „Rothensee" und
     * „Rothensee (Schleife)" sind dieselbe Haltestelle, heißen aber verschieden.
     */
    public function test_two_stop_groups_can_be_merged_by_hand(): void
    {
        $this->halt('Rothensee');
        $this->halt('Rothensee (Schleife)');

        $service = app(StopGroupService::class);
        $service->sync();

        $ziel = StopGroup::query()->where('name', 'Rothensee')->firstOrFail();
        $quelle = StopGroup::query()->where('name', 'Rothensee (Schleife)')->firstOrFail();

        $this->withToken($this->token())
            ->postJson("/api/v1/admin/stop-groups/{$ziel->id}/merge", ['source_id' => $quelle->id])
            ->assertNoContent();

        $this->assertNull(StopGroup::query()->find($quelle->id));
        $this->assertSame(2, StopGroupMember::query()->where('stop_group_id', $ziel->id)->count());

        // Ein erneuter Automatik-Lauf darf die Pflege nicht zurücknehmen.
        $service->sync();
        $this->assertSame(2, StopGroupMember::query()->where('stop_group_id', $ziel->id)->count());
        $this->assertSame(1, StopGroup::query()->count());
    }

    public function test_a_stop_can_be_assigned_by_hand_and_survives_the_next_sync(): void
    {
        $eins = $this->halt('Buckau (Wasserwerk)');
        $zwei = $this->halt('Buckau (Wasserwerk) Wendeschl.');

        $service = app(StopGroupService::class);
        $service->sync();

        $ziel = StopGroup::query()->where('name', 'Buckau (Wasserwerk)')->firstOrFail();

        $this->withToken($this->token())
            ->postJson("/api/v1/admin/stop-groups/{$ziel->id}/stops", ['consolidated_stop_id' => $zwei->id])
            ->assertNoContent();

        $service->sync();

        $mitglieder = StopGroupMember::query()->where('stop_group_id', $ziel->id)->pluck('consolidated_stop_id');
        $this->assertEqualsCanonicalizing([$eins->id, $zwei->id], $mitglieder->all());

        // Die leergeräumte Gruppe ist verschwunden — eine Haltestelle ohne Halte ist keine.
        $this->assertSame(1, StopGroup::query()->count());
    }

    public function test_detaching_returns_a_stop_to_its_name_group(): void
    {
        $this->halt('Rothensee');
        $schleife = $this->halt('Rothensee (Schleife)');

        $service = app(StopGroupService::class);
        $service->sync();

        $ziel = StopGroup::query()->where('name', 'Rothensee')->firstOrFail();
        $service->assign($schleife->id, $ziel);
        $this->assertSame(1, StopGroup::query()->count());

        $this->withToken($this->token())
            ->deleteJson("/api/v1/admin/stop-groups/{$ziel->id}/stops/{$schleife->id}")
            ->assertNoContent();

        $this->assertSame(2, StopGroup::query()->count());
        $this->assertSame(
            $schleife->id,
            StopGroupMember::query()->where('consolidated_stop_id', $schleife->id)->value('consolidated_stop_id'),
        );
    }

    public function test_merging_a_group_into_itself_is_rejected(): void
    {
        $this->halt('Sudenburg');
        app(StopGroupService::class)->sync();
        $gruppe = StopGroup::query()->firstOrFail();

        $this->withToken($this->token())
            ->postJson("/api/v1/admin/stop-groups/{$gruppe->id}/merge", ['source_id' => $gruppe->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    /**
     * Vorschläge sind eine Frage, keine Entscheidung: Eine reine Abstandsregel würde an
     * dichten Kreuzungen Falsches verschmelzen.
     */
    public function test_nearby_groups_are_offered_as_suggestions(): void
    {
        $this->halt('Rothensee', 52.2000, 11.6500);
        $this->halt('Rothensee (Schleife)', 52.2018, 11.6500);   // rund 200 m
        $this->halt('Weit Weg', 52.3000, 11.6500);               // rund 11 km

        app(StopGroupService::class)->sync();
        $gruppe = StopGroup::query()->where('name', 'Rothensee')->firstOrFail();

        $daten = $this->withToken($this->token())
            ->getJson("/api/v1/admin/stop-groups/{$gruppe->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(['Rothensee (Schleife)'], array_column($daten['suggestions'], 'name'));
        $this->assertEqualsWithDelta(200, $daten['suggestions'][0]['distance_meters'], 15);
    }

    public function test_directory_marks_one_sided_stop_groups(): void
    {
        $f = new ConsolidatedFixtures;
        $version = $f->version('10');
        $f->gueltigkeit($version);
        $f->fahrt($version, ['Alter Markt', 'Rothensee'], ['06:00:00', '06:30:00']);

        $daten = $this->withToken($this->token())
            ->getJson('/api/v1/admin/stop-groups?only_termini=1')
            ->assertOk()
            ->json('data');

        $rothensee = collect($daten)->firstWhere('name', 'Rothensee');

        $this->assertNotNull($rothensee);
        $this->assertTrue($rothensee['one_sided']);
        $this->assertSame(['10'], $rothensee['ending_lines']);
        $this->assertSame([], $rothensee['starting_lines']);

        // Reine Durchfahrts-Halte tauchen in dieser Ansicht gar nicht erst auf.
        $this->assertNull(collect($daten)->firstWhere('name', 'Irgendwo'));
    }

    // ------------------------------------------- Arbeitslast je Haltestelle (Anschlusseditor)

    /**
     * Mit Periode und Fahrplantyp trägt das Verzeichnis die Arbeitslast: welche Verkehrsmittel
     * hier vorkommen und wie viel davon noch offen ist. Daran hängt die Auswahlliste im
     * Anschlusseditor — wer die Straßenbahn abarbeitet, hat an einer reinen Buslinie nichts zu
     * tun, und eine fertig gepflegte Haltestelle soll zurücktreten.
     */
    public function test_the_directory_reports_modes_and_open_trips_per_stop_group(): void
    {
        $f = new ConsolidatedFixtures;
        $periode = $f->periode();

        $tram = $f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $f->gueltigkeit($tram);
        $f->fahrt($tram, ['Kannenstieg', 'Sudenburg'], ['06:00:00', '06:30:00']);

        $bus = $f->version('48', FahrplanTyp::MoFrNormal, 1, $periode);
        $f->gueltigkeit($bus);
        $busFahrt = $f->fahrt($bus, ['Olvenstedt', 'Sudenburg'], ['06:10:00', '06:40:00']);
        $busFahrt->update(['route_type' => 3]);

        $daten = $this->withToken($this->token())
            ->getJson(sprintf('/api/v1/admin/stop-groups?only_termini=1&period=%d&day_type=mo_fr', $periode->id))
            ->assertOk()
            ->json('data');

        $sudenburg = collect($daten)->firstWhere('name', 'Sudenburg');

        $this->assertSame(['bus', 'tram'], $sudenburg['modes']);
        $this->assertSame(1, $sudenburg['open']['tram']);
        $this->assertSame(1, $sudenburg['open']['bus']);
        $this->assertSame(2, $sudenburg['open']['total']);
    }

    /** Was entschieden ist, zählt nicht mehr als offen — sonst bliebe die Liste ewig voll. */
    public function test_a_decided_trip_no_longer_counts_as_open(): void
    {
        $f = new ConsolidatedFixtures;
        $periode = $f->periode();

        $version = $f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $f->gueltigkeit($version);
        $fahrt = $f->fahrt($version, ['Kannenstieg', 'Sudenburg'], ['06:00:00', '06:30:00']);

        $abfrage = sprintf('/api/v1/admin/stop-groups?only_termini=1&period=%d&day_type=mo_fr', $periode->id);

        $vorher = collect($this->withToken($this->token())->getJson($abfrage)->json('data'))
            ->firstWhere('name', 'Sudenburg');

        $this->assertSame(1, $vorher['open']['tram']);

        TripLink::factory()->end()->create([
            'from_trip_id' => $fahrt->id,
            'stop_id' => $fahrt->last_stop_id,
        ]);

        $nachher = collect($this->withToken($this->token())->getJson($abfrage)->json('data'))
            ->firstWhere('name', 'Sudenburg');

        // Das Verkehrsmittel bleibt — die Haltestelle verschwindet nicht aus der Auswahl,
        // nur weil dort nichts mehr zu tun ist.
        $this->assertSame(['tram'], $nachher['modes']);
        $this->assertSame(0, $nachher['open']['tram']);
    }

    /** Ohne Periode und Fahrplantyp bleibt das Verzeichnis wie bisher — beides gehört zusammen. */
    public function test_without_period_and_day_type_the_workload_is_absent(): void
    {
        $f = new ConsolidatedFixtures;
        $version = $f->version('1');
        $f->gueltigkeit($version);
        $f->fahrt($version, ['Kannenstieg', 'Sudenburg'], ['06:00:00', '06:30:00']);

        $sudenburg = collect($this->withToken($this->token())->getJson('/api/v1/admin/stop-groups?only_termini=1')->json('data'))
            ->firstWhere('name', 'Sudenburg');

        $this->assertSame([], $sudenburg['modes']);
        $this->assertNull($sudenburg['open']);
    }

    /** Eine andere Periode zählt eigene Fahrten — sonst stünde über der Liste ein falscher Stand. */
    public function test_another_period_is_not_counted(): void
    {
        $f = new ConsolidatedFixtures;
        $periode = $f->periode();

        $version = $f->version('1', FahrplanTyp::MoFrNormal, 1, $periode);
        $f->gueltigkeit($version);
        $f->fahrt($version, ['Kannenstieg', 'Sudenburg'], ['06:00:00', '06:30:00']);

        $andere = SchedulePeriod::factory()->create(['label' => 'Andere', 'valid_from' => '2027-01-01']);

        $daten = $this->withToken($this->token())
            ->getJson(sprintf('/api/v1/admin/stop-groups?only_termini=1&period=%d&day_type=mo_fr', $andere->id))
            ->json('data');

        $sudenburg = collect($daten)->firstWhere('name', 'Sudenburg');

        $this->assertSame([], $sudenburg['modes']);
        $this->assertNull($sudenburg['open']);
    }

    public function test_a_group_can_be_created_and_renamed(): void
    {
        $id = $this->withToken($this->token())
            ->postJson('/api/v1/admin/stop-groups', ['name' => 'Betriebshof Nord'])
            ->assertCreated()
            ->json('data.id');

        $this->withToken($this->token())
            ->putJson("/api/v1/admin/stop-groups/{$id}", ['name' => 'Betriebshof Rothensee'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Betriebshof Rothensee');

        // Von Hand angelegt heisst: kein Namensschluessel, sammelt also nichts automatisch ein.
        $this->assertNull(StopGroup::query()->findOrFail($id)->name_key);
    }
}
