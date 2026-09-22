<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\TripLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Das Betriebshof-Verzeichnis (KURSE §3.2).
 *
 * **Die Migrationen legen drei Höfe an** — Nord und Westerhüsen für die Straßenbahn,
 * Kroatenwuhne für den Bus. Die Tests rechnen damit, statt von einer leeren Tabelle auszugehen:
 * Genau so findet der Pflegende es auch vor.
 */
final class DepotTest extends TestCase
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

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/depots')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    /**
     * Die drei Magdeburger Höfe, jeder mit seinem Verkehrsmittel: Nord und Westerhüsen nehmen
     * Straßenbahnen auf, Kroatenwuhne Busse. Ohne die Trennung böte die Auswahl an einer
     * Busfahrt einen Tram-Hof mit an, und der erste Fehlgriff stünde als Tatsache in den Daten.
     */
    public function test_the_magdeburg_depots_are_seeded_with_their_modes(): void
    {
        $hoefe = $this->withToken($this->token())
            ->getJson('/api/v1/admin/depots')
            ->assertOk()
            ->json('data');

        $nachName = array_column($hoefe, null, 'name');

        $this->assertSame(
            ['Betriebshof Kroatenwuhne', 'Betriebshof Nord', 'Betriebshof Westerhüsen'],
            array_column($hoefe, 'name'),
        );

        $this->assertSame(['tram'], $nachName['Betriebshof Nord']['modes']);
        $this->assertSame(['tram'], $nachName['Betriebshof Westerhüsen']['modes']);
        $this->assertSame(['bus'], $nachName['Betriebshof Kroatenwuhne']['modes']);

        // Der Busbetriebshof ist keiner Haltestelle zugeordnet — genau der Fall, an dem sich
        // zeigt, dass der Hof eine eigene Entität ist und kein Haken an einer Haltestelle.
        // Dort greift die Automatik nicht; der Hof wird von Hand gewählt.
        $this->assertSame([], $nachName['Betriebshof Kroatenwuhne']['stop_groups']);
    }

    /**
     * **Mehrere Haltestellen je Hof**, und das ist der Regelfall: Die Westerhüsener Fahrten
     * rücken fast immer an der *Schleswiger Straße* aus, nicht am Hof selbst — der Weg dorthin
     * ist die Betriebsfahrt, die im Fahrplan gar nicht steht.
     */
    public function test_a_depot_can_carry_several_stop_groups(): void
    {
        $a = $this->f->haltestelle('Westerhüsen (Betriebshof)');
        $b = $this->f->haltestelle('Schleswiger Straße');

        $angelegt = $this->withToken($this->token())
            ->postJson('/api/v1/admin/depots', [
                'name' => 'Hof mit zwei Halten',
                'stop_group_ids' => [$a->id, $b->id],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(
            ['Schleswiger Straße', 'Westerhüsen (Betriebshof)'],
            array_column($angelegt['stop_groups'], 'name'),
        );

        // Eine leere Liste hebt die Zuordnung auf — sonst liesse sich ein Fehlgriff nie
        // zurücknehmen.
        $this->withToken($this->token())
            ->putJson('/api/v1/admin/depots/'.$angelegt['id'], [
                'name' => 'Hof mit zwei Halten',
                'stop_group_ids' => [],
            ])
            ->assertOk()
            ->assertJsonPath('data.stop_groups', []);
    }

    public function test_a_depot_can_be_created_and_renamed(): void
    {
        $angelegt = $this->withToken($this->token())
            ->postJson('/api/v1/admin/depots', ['name' => 'Abstellanlage Rothensee', 'short_name' => 'Rothensee'])
            ->assertCreated()
            ->json('data');

        $this->assertSame('Rothensee', $angelegt['display']);
        $this->assertSame(0, $angelegt['usage_count']);
        // Leer heißt „alle Verkehrsmittel" — nicht „keines".
        $this->assertSame([], $angelegt['modes']);
        $this->assertTrue($angelegt['active']);

        $this->withToken($this->token())
            ->putJson('/api/v1/admin/depots/'.$angelegt['id'], ['name' => 'Rothensee', 'short_name' => null])
            ->assertOk()
            ->assertJsonPath('data.name', 'Rothensee')
            // Ohne Kurzform trägt die Anzeige den vollen Namen — nie etwas Leeres.
            ->assertJsonPath('data.display', 'Rothensee');
    }

    public function test_a_duplicate_name_is_rejected(): void
    {
        $this->withToken($this->token())
            ->postJson('/api/v1/admin/depots', ['name' => 'Betriebshof Nord'])
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'Einen Betriebshof mit diesem Namen gibt es schon.');
    }

    public function test_renaming_a_depot_to_its_own_name_is_allowed(): void
    {
        $hof = Depot::query()->where('name', 'Betriebshof Nord')->firstOrFail();

        $this->withToken($this->token())
            ->putJson('/api/v1/admin/depots/'.$hof->id, ['name' => 'Betriebshof Nord', 'short_name' => 'N'])
            ->assertOk()
            ->assertJsonPath('data.display', 'N');
    }

    public function test_an_unused_depot_can_be_deleted(): void
    {
        $hof = Depot::factory()->create();

        $this->withToken($this->token())
            ->deleteJson('/api/v1/admin/depots/'.$hof->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('depots', ['id' => $hof->id]);
    }

    /**
     * Ein Hof, an dem Entscheidungen hängen, wird nicht gelöscht — sonst ginge die Angabe
     * „ausgerückt aus Nord" still verloren. Stilllegen ist der Weg.
     */
    public function test_a_used_depot_cannot_be_deleted_but_can_be_retired(): void
    {
        $version = $this->f->version();
        $this->f->gueltigkeit($version);
        $fahrt = $this->f->fahrt($version, ['Sudenburg', 'Kannenstieg'], ['06:00:00', '06:30:00']);

        $hof = Depot::factory()->create();

        TripLink::factory()->start()->atDepot($hof)->create([
            'to_trip_id' => $fahrt->id,
            'stop_id' => $fahrt->first_stop_id,
        ]);

        $this->withToken($this->token())
            ->deleteJson('/api/v1/admin/depots/'.$hof->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 409);

        $this->assertDatabaseHas('depots', ['id' => $hof->id]);

        $this->withToken($this->token())
            ->putJson('/api/v1/admin/depots/'.$hof->id, ['name' => $hof->name, 'active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.usage_count', 1);
    }

    public function test_only_active_depots_are_listed_when_asked(): void
    {
        Depot::factory()->inactive()->create(['name' => 'Alter Hof']);

        $alle = $this->withToken($this->token())->getJson('/api/v1/admin/depots')->json('data.*.name');
        $aktive = $this->withToken($this->token())
            ->getJson('/api/v1/admin/depots?active_only=1')
            ->json('data.*.name');

        $this->assertContains('Alter Hof', $alle);
        $this->assertNotContains('Alter Hof', $aktive);
    }

    public function test_an_unknown_mode_is_rejected(): void
    {
        $this->withToken($this->token())
            ->postJson('/api/v1/admin/depots', ['name' => 'Fährhof', 'modes' => ['ship']])
            ->assertStatus(422)
            ->assertJsonPath(
                'error.message',
                'Ein Betriebshof nimmt Tram oder Bus auf — etwas anderes gibt es im Netz nicht.',
            );
    }
}
