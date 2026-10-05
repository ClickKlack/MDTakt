<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Models\Depot;
use App\Models\LineVersion;
use App\Models\TripLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Fahrplan einer Linien-Version als Matrix.
 */
final class TimetableTest extends TestCase
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

    private function hole(LineVersion $version): array
    {
        return $this->withToken($this->token())
            ->getJson("/api/v1/admin/line-versions/{$version->id}/timetable")
            ->assertOk()
            ->json('data');
    }

    public function test_requires_authentication(): void
    {
        $version = $this->f->version();

        $this->getJson("/api/v1/admin/line-versions/{$version->id}/timetable")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_unknown_version_yields_404(): void
    {
        $this->withToken($this->token())
            ->getJson('/api/v1/admin/line-versions/999999/timetable')
            ->assertStatus(404);
    }

    public function test_stops_are_rows_and_trips_are_columns(): void
    {
        $version = $this->f->version('1', FahrplanTyp::MoFrNormal);
        $this->f->fahrt($version, ['Kannenstieg', 'Milchweg', 'Listemannstr.'], ['07:00:00', '07:05:00', '07:12:00']);
        $this->f->fahrt($version, ['Kannenstieg', 'Milchweg', 'Listemannstr.'], ['07:20:00', '07:25:00', '07:32:00']);

        $daten = $this->hole($version);

        $this->assertCount(1, $daten['directions']);
        $richtung = $daten['directions'][0];

        $this->assertSame(['Kannenstieg', 'Milchweg', 'Listemannstr.'], array_column($richtung['rows'], 'stop_name'));
        $this->assertSame('Kannenstieg', $richtung['start_stop']);
        $this->assertSame('Listemannstr.', $richtung['end_stop']);
        $this->assertSame(2, $richtung['trip_count']);
        $this->assertSame(1, $richtung['variant_count']);
        $this->assertFalse($richtung['alignment_warning']);

        $this->assertSame(['07:00', '07:05', '07:12'], $richtung['trips'][0]['cells']);
        $this->assertSame(['07:20', '07:25', '07:32'], $richtung['trips'][1]['cells']);
    }

    public function test_cells_are_positionally_aligned_to_rows(): void
    {
        $version = $this->f->version();
        $this->f->fahrt($version, ['A', 'B', 'C', 'D'], ['08:00:00', '08:05:00', '08:10:00', '08:15:00']);
        // Kurzlaeufer ohne B — die Luecke muss an Zeile 1 sitzen, nicht am Ende.
        $this->f->fahrt($version, ['A', 'C', 'D'], ['09:00:00', '09:10:00', '09:15:00']);

        $richtung = $this->hole($version)['directions'][0];

        $this->assertSame(['A', 'B', 'C', 'D'], array_column($richtung['rows'], 'stop_name'));
        $this->assertSame(['08:00', '08:05', '08:10', '08:15'], $richtung['trips'][0]['cells']);
        $this->assertSame(['09:00', null, '09:10', '09:15'], $richtung['trips'][1]['cells']);
        $this->assertSame(2, $richtung['variant_count']);

        foreach ($richtung['trips'] as $fahrt) {
            $this->assertCount(count($richtung['rows']), $fahrt['cells'], 'cells muss so lang sein wie rows');
        }
    }

    public function test_a_stop_touched_twice_gets_two_rows_with_separate_times(): void
    {
        $version = $this->f->version();
        $this->f->fahrt(
            $version,
            ['Bördepark', 'Wanzleber Chaussee', 'Sonnenanger', 'Wanzleber Chaussee', 'Am Teich'],
            ['08:00:00', '08:04:00', '08:07:00', '08:11:00', '08:15:00'],
        );

        $richtung = $this->hole($version)['directions'][0];
        $zeilen = $richtung['rows'];

        $this->assertCount(5, $zeilen);
        $this->assertSame($zeilen[1]['stop_id'], $zeilen[3]['stop_id'], 'Dieselbe Identitaet …');
        $this->assertSame(0, $zeilen[1]['repeat_index']);
        $this->assertSame(1, $zeilen[3]['repeat_index'], '… aber als zweite Beruehrung markiert');

        // Beide Zeiten muessen erhalten bleiben — genau das ginge bei einer Zeile je Identitaet verloren.
        $this->assertSame(['08:00', '08:04', '08:07', '08:11', '08:15'], $richtung['trips'][0]['cells']);
    }

    public function test_opposite_directions_are_separate_and_sorted_by_trip_count(): void
    {
        $version = $this->f->version();
        $this->f->fahrt($version, ['A', 'B'], ['08:00:00', '08:10:00']);
        $this->f->fahrt($version, ['B', 'A'], ['09:00:00', '09:10:00']);
        $this->f->fahrt($version, ['B', 'A'], ['10:00:00', '10:10:00']);

        $richtungen = $this->hole($version)['directions'];

        $this->assertCount(2, $richtungen);
        $this->assertSame('B', $richtungen[0]['start_stop'], 'Die meistbefahrene Richtung zuerst');
        $this->assertSame(2, $richtungen[0]['trip_count']);
        $this->assertSame(1, $richtungen[1]['trip_count']);
    }

    public function test_columns_are_ordered_by_their_first_served_stop(): void
    {
        $version = $this->f->version();
        // Ein Kurzlaeufer, der erst ab C faehrt, aber frueher als die durchgehende Fahrt.
        $this->f->fahrt($version, ['A', 'B', 'C', 'D'], ['09:00:00', '09:05:00', '09:10:00', '09:15:00']);
        $this->f->fahrt($version, ['A', 'B', 'C', 'D'], ['06:00:00', '06:05:00', '06:10:00', '06:15:00']);

        $richtung = $this->hole($version)['directions'][0];

        $this->assertSame('06:00', $richtung['trips'][0]['cells'][0]);
        $this->assertSame('09:00', $richtung['trips'][1]['cells'][0]);
    }

    public function test_times_beyond_midnight_keep_their_hour_and_sort_last(): void
    {
        $version = $this->f->version('N8', FahrplanTyp::SoFeiertag);
        $this->f->fahrt($version, ['A', 'B'], ['25:10:00', '25:30:00']);
        $this->f->fahrt($version, ['A', 'B'], ['23:50:00', '23:58:00']);

        $richtung = $this->hole($version)['directions'][0];

        // Nicht auf 01:10 umgerechnet — GTFS-Zeiten beziehen sich auf den Betriebstag.
        $this->assertSame(['23:50', '23:58'], $richtung['trips'][0]['cells']);
        $this->assertSame(['25:10', '25:30'], $richtung['trips'][1]['cells']);
    }

    public function test_version_without_content_yields_empty_directions(): void
    {
        $version = $this->f->version();

        $daten = $this->hole($version);

        $this->assertSame([], $daten['directions']);
        $this->assertSame(0, $daten['line_version']['trip_count']);
    }

    public function test_response_carries_version_and_period_context(): void
    {
        $version = $this->f->version('5', FahrplanTyp::Sa, 3);
        $this->f->fahrt($version, ['A', 'B'], ['07:00:00', '07:10:00']);

        $daten = $this->hole($version);

        $this->assertSame('5', $daten['line_version']['line']);
        $this->assertSame('sa', $daten['line_version']['day_type']);
        $this->assertSame(3, $daten['line_version']['version_no']);
        $this->assertSame('current', $daten['period']['status']);
    }

    public function test_links_name_predecessor_and_successor_with_jump_target(): void
    {
        $eins = $this->f->version('1');
        $fuenf = $this->f->version('5');
        $zubringer = $this->f->fahrt($eins, ['Hbf', 'City Carré'], ['07:00:00', '07:10:00']);
        $fahrt = $this->f->fahrt($fuenf, ['City Carré', 'Messe'], ['07:12:00', '07:30:00']);

        TripLink::factory()->create([
            'from_trip_id' => $zubringer->id,
            'to_trip_id' => $fahrt->id,
            'stop_id' => $zubringer->last_stop_id,
        ]);

        $spalte = $this->hole($fuenf)['directions'][0]['trips'][0];

        $this->assertCount(1, $spalte['links']['before']);
        $this->assertSame([], $spalte['links']['after']);

        $davor = $spalte['links']['before'][0];
        $this->assertSame('link', $davor['kind']);
        $this->assertSame(120, $davor['turnaround_seconds']);
        $this->assertSame($zubringer->id, $davor['trip']['id']);
        $this->assertSame('1', $davor['trip']['line']);
        $this->assertSame($eins->id, $davor['trip']['line_version_id']);
        $this->assertSame('mo_fr', $davor['trip']['day_type']);
        $this->assertSame($eins->period_id, $davor['trip']['period_id']);
        $this->assertSame('Hbf', $davor['trip']['start_stop']);

        // Dieselbe Kante aus Sicht des Vorgängers: dort steht sie danach.
        $zurueck = $this->hole($eins)['directions'][0]['trips'][0]['links'];
        $this->assertSame($fahrt->id, $zurueck['after'][0]['trip']['id']);
        $this->assertSame([], $zurueck['before']);
    }

    public function test_links_show_depot_decisions_without_partner(): void
    {
        $version = $this->f->version('1');
        $fahrt = $this->f->fahrt($version, ['Hof', 'Hbf'], ['05:00:00', '05:20:00']);
        $hof = Depot::factory()->create(['name' => 'Betriebshof Testheide', 'short_name' => 'Testh.']);

        TripLink::factory()->start()->atDepot($hof)->create([
            'to_trip_id' => $fahrt->id,
            'stop_id' => $fahrt->first_stop_id,
        ]);
        TripLink::factory()->end()->create([
            'from_trip_id' => $fahrt->id,
            'stop_id' => $fahrt->last_stop_id,
        ]);

        $links = $this->hole($version)['directions'][0]['trips'][0]['links'];

        $this->assertSame('start', $links['before'][0]['kind']);
        $this->assertNull($links['before'][0]['trip']);
        $this->assertSame('Testh.', $links['before'][0]['depot']);
        $this->assertSame('end', $links['after'][0]['kind']);
        $this->assertNull($links['after'][0]['depot']);
    }

    public function test_links_list_every_predecessor_of_a_trip(): void
    {
        $eins = $this->f->version('1');
        $alt = $this->f->version('13', FahrplanTyp::MoFrNormal, 1);
        $neu = $this->f->version('13', FahrplanTyp::MoFrNormal, 2);
        $fahrt = $this->f->fahrt($eins, ['City Carré', 'Hbf'], ['08:00:00', '08:20:00']);
        $vorher = $this->f->fahrt($alt, ['Messe', 'City Carré'], ['07:40:00', '07:55:00']);
        $nachher = $this->f->fahrt($neu, ['Messe', 'City Carré'], ['07:41:00', '07:56:00']);

        foreach ([$vorher, $nachher] as $vorgaenger) {
            TripLink::factory()->create([
                'from_trip_id' => $vorgaenger->id,
                'to_trip_id' => $fahrt->id,
                'stop_id' => $fahrt->first_stop_id,
            ]);
        }

        $davor = $this->hole($eins)['directions'][0]['trips'][0]['links']['before'];

        $this->assertSame([$vorher->id, $nachher->id], array_column(array_column($davor, 'trip'), 'id'));
    }

    public function test_trip_without_decision_has_empty_links(): void
    {
        $version = $this->f->version('1');
        $this->f->fahrt($version, ['A', 'B'], ['07:00:00', '07:10:00']);

        $links = $this->hole($version)['directions'][0]['trips'][0]['links'];

        $this->assertSame(['before' => [], 'after' => []], $links);
    }
}
