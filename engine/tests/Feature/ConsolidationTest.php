<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\ConsolidatedStop;
use App\Models\ConsolidatedStopTime;
use App\Models\ConsolidatedStopVersion;
use App\Models\ConsolidatedTrip;
use App\Models\LineVersion;
use App\Models\Route;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use App\Services\ScheduleVersionService;
use App\Services\TripSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fahrplan-Konsolidat Phase C: Halte-Dedup und dauerhafte Fahrplan-Inhalte
 * (FAHRPLANPERIODEN §5.1, §5.3).
 */
final class ConsolidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, string>  $zeiten
     * @param  array<int, string>  $halte
     */
    private function fahrt(string $tripId, string $serviceId, string $routeId, array $zeiten, array $halte): void
    {
        Trip::factory()->create(['trip_id' => $tripId, 'route_id' => $routeId, 'service_id' => $serviceId]);

        foreach ($zeiten as $i => $zeit) {
            StopTime::factory()->create([
                'trip_id' => $tripId,
                'stop_id' => $halte[$i],
                'stop_sequence' => $i + 1,
                'departure_time' => $zeit,
                'arrival_time' => $zeit,
            ]);
        }
    }

    private function werktagsService(string $serviceId, string $von, string $bis): void
    {
        Calendar::factory()->create([
            'service_id' => $serviceId,
            'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true,
            'friday' => true, 'saturday' => false, 'sunday' => false,
            'start_date' => $von, 'end_date' => $bis,
        ]);
    }

    private function konsolidieren(): array
    {
        app(TripSignatureService::class)->rebuild();

        return app(ScheduleVersionService::class)->updateFromCurrentImport();
    }

    // ---------------------------------------------------------------- Halte-Dedup

    public function test_same_stop_with_spelling_variant_within_threshold_becomes_one_identity(): void
    {
        // 6,3 m auseinander, Schreibvariante — im Realbestand exakt dieser Fall.
        Stop::factory()->create(['stop_id' => 'A1', 'stop_name' => 'Listemannstr.', 'lat' => 52.1400000, 'lon' => 11.6300000]);
        Stop::factory()->create(['stop_id' => 'A2', 'stop_name' => 'Listemannstraße', 'lat' => 52.1400500, 'lon' => 11.6300200]);

        $this->minimalerFahrplan('A1', 'A2');
        $this->konsolidieren();

        $this->assertSame(1, ConsolidatedStop::query()->count(), 'Schreibvarianten desselben Halts gehören zusammen');
    }

    public function test_distinct_stops_beyond_the_threshold_stay_separate(): void
    {
        // ~17 m auseinander und unterscheidender Zusatz — die erste echte Fehlverschmelzung
        // im Realbestand lag genau hier (City Carré ↔ City Carré / Ersatzh.).
        Stop::factory()->create(['stop_id' => 'B1', 'stop_name' => 'City Carré', 'lat' => 52.1400000, 'lon' => 11.6300000]);
        Stop::factory()->create(['stop_id' => 'B2', 'stop_name' => 'City Carré / Ersatzh.', 'lat' => 52.1401500, 'lon' => 11.6300000]);

        $this->minimalerFahrplan('B1', 'B2');
        $this->konsolidieren();

        $this->assertSame(2, ConsolidatedStop::query()->count(), 'Eigenständige Halte dürfen nicht verschmelzen');
    }

    public function test_platforms_on_identical_coordinates_merge_into_one_stop(): void
    {
        // Realbefund: 18 Halte liegen auf exakt identischen Koordinaten und tragen dort die
        // beiden Fahrtrichtungen. Keine Schwelle trennt die — bewusst ein Halt (§5.1).
        Stop::factory()->create(['stop_id' => 'C1', 'stop_name' => 'Maybachstraße', 'lat' => 52.1400000, 'lon' => 11.6300000]);
        Stop::factory()->create(['stop_id' => 'C2', 'stop_name' => 'Maybachstraße', 'lat' => 52.1400000, 'lon' => 11.6300000]);

        $this->minimalerFahrplan('C1', 'C2');
        $this->konsolidieren();

        $halt = ConsolidatedStop::query()->sole();

        // Und genau eine Attribut-Version: Zwei Roh-Halte auf einer Identität dürfen sich
        // nicht gegenseitig überschreiben, sonst sähe jeder Import wie eine Umbenennung aus.
        $this->assertCount(1, $halt->versions);
        $this->assertTrue(
            $halt->versions->first()->valid_to->greaterThanOrEqualTo($halt->versions->first()->valid_from),
            'Ein Gültigkeits-Intervall darf nicht rückwärts laufen',
        );
    }

    public function test_merged_spelling_variants_produce_a_single_attribute_version(): void
    {
        Stop::factory()->create(['stop_id' => 'E1', 'stop_name' => 'Listemannstr.', 'lat' => 52.1400000, 'lon' => 11.6300000]);
        Stop::factory()->create(['stop_id' => 'E2', 'stop_name' => 'Listemannstraße', 'lat' => 52.1400500, 'lon' => 11.6300200]);

        $this->minimalerFahrplan('E1', 'E2');
        $this->konsolidieren();

        $halt = ConsolidatedStop::query()->sole();
        $this->assertCount(1, $halt->versions);
        $this->assertSame(0, ConsolidatedStopVersion::query()->whereColumn('valid_to', '<', 'valid_from')->count());
    }

    public function test_merged_platforms_keep_their_attributes_when_stop_ids_are_reshuffled(): void
    {
        // Zwei Steige, 9 m auseinander, gleicher Name — sie verschmelzen zu einer Identitaet.
        Stop::factory()->create(['stop_id' => 'A-1', 'stop_name' => 'Hasselbachplatz', 'lat' => 52.1209000, 'lon' => 11.6273520]);
        Stop::factory()->create(['stop_id' => 'A-2', 'stop_name' => 'Hasselbachplatz', 'lat' => 52.1209680, 'lon' => 11.6274650]);
        Stop::factory()->create(['stop_id' => 'B-1', 'stop_name' => 'Beta', 'lat' => 52.1500000, 'lon' => 11.6400000]);
        $this->minimalerFahrplan('A-1', 'B-1');
        $this->konsolidieren();

        $halt = ConsolidatedStop::query()->where('name_key', 'hasselbachplatz')->sole();
        $this->assertCount(1, $halt->versions);

        // Folge-Import: gtfs.de vergibt alle stop_ids neu, und der zweite Steig traegt jetzt
        // die kleinere ID. Am Ort hat sich nichts geaendert.
        StopTime::query()->delete();
        Trip::query()->delete();
        Stop::query()->delete();
        Stop::factory()->create(['stop_id' => 'X-1', 'stop_name' => 'Hasselbachplatz', 'lat' => 52.1209680, 'lon' => 11.6274650]);
        Stop::factory()->create(['stop_id' => 'X-2', 'stop_name' => 'Hasselbachplatz', 'lat' => 52.1209000, 'lon' => 11.6273520]);
        Stop::factory()->create(['stop_id' => 'Y-1', 'stop_name' => 'Beta', 'lat' => 52.1500000, 'lon' => 11.6400000]);
        $this->minimalerFahrplan('X-1', 'Y-1');
        $this->konsolidieren();

        // Ohne ankerbasierte Wahl haette hier der andere Steig gewonnen und die Historie
        // eine Verlegung um 9 m behauptet, die nie stattgefunden hat.
        $this->assertCount(
            1,
            $halt->refresh()->versions,
            'Neu gewuerfelte stop_ids duerfen keine Schein-Verlegung erzeugen',
        );
    }

    public function test_stop_identity_survives_a_reimport_with_new_stop_ids(): void
    {
        Stop::factory()->create(['stop_id' => 'ALT-1', 'stop_name' => 'Alpha', 'lat' => 52.1400000, 'lon' => 11.6300000]);
        Stop::factory()->create(['stop_id' => 'ALT-2', 'stop_name' => 'Beta', 'lat' => 52.1500000, 'lon' => 11.6400000]);
        $this->minimalerFahrplan('ALT-1', 'ALT-2');
        $this->konsolidieren();

        $ids = ConsolidatedStop::query()->orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $ids);

        // Re-Import: gtfs.de vergibt neue Surrogat-IDs, die Koordinaten wandern minimal.
        StopTime::query()->delete();
        Trip::query()->delete();
        Stop::query()->delete();
        Stop::factory()->create(['stop_id' => 'NEU-1', 'stop_name' => 'Alpha', 'lat' => 52.1400300, 'lon' => 11.6300100]);
        Stop::factory()->create(['stop_id' => 'NEU-2', 'stop_name' => 'Beta', 'lat' => 52.1500000, 'lon' => 11.6400000]);
        $this->minimalerFahrplan('NEU-1', 'NEU-2');
        $this->konsolidieren();

        // Das ist der Kern: Ohne Dedup wären hier jetzt vier Halte.
        $this->assertSame($ids, ConsolidatedStop::query()->orderBy('id')->pluck('id')->all());
    }

    public function test_renaming_a_stop_keeps_the_identity_when_it_stays_close(): void
    {
        Stop::factory()->create(['stop_id' => 'D1', 'stop_name' => 'Magdeburg, Zoo', 'lat' => 52.1400000, 'lon' => 11.6300000]);
        Stop::factory()->create(['stop_id' => 'D2', 'stop_name' => 'Beta', 'lat' => 52.1500000, 'lon' => 11.6400000]);
        $this->minimalerFahrplan('D1', 'D2');
        $this->konsolidieren();

        $halt = ConsolidatedStop::query()->orderBy('id')->first();
        $this->assertSame('Magdeburg, Zoo', $halt->versions()->sole()->name);

        // Der Feed schreibt den Halt künftig ohne Ortspräfix — normalisiert derselbe Name.
        Stop::query()->where('stop_id', 'D1')->update(['stop_name' => 'Zoo']);
        $this->konsolidieren();

        $this->assertSame(2, ConsolidatedStop::query()->count(), 'Umbenennung darf keine neue Identität erzeugen');

        // Die Historie hält beide Namen mit ihrem beobachteten Zeitraum fest.
        $versionen = $halt->versions()->orderBy('valid_from')->get();
        $this->assertCount(2, $versionen);
        $this->assertSame('Magdeburg, Zoo', $versionen[0]->name);
        $this->assertSame('Zoo', $versionen[1]->name);
        $this->assertTrue($versionen[1]->from_confirmed, 'Der Wechsel selbst ist beobachtet');
        $this->assertFalse($versionen[0]->from_confirmed, 'Die erste Sichtung ist nur eine Untergrenze');
    }

    // ---------------------------------------------------------- Fahrplan-Inhalte

    public function test_trips_and_stop_times_are_written_for_each_version(): void
    {
        Stop::factory()->create(['stop_id' => 'S1', 'stop_name' => 'Alpha', 'lat' => 52.1400000, 'lon' => 11.6300000]);
        Stop::factory()->create(['stop_id' => 'S2', 'stop_name' => 'Beta', 'lat' => 52.1500000, 'lon' => 11.6400000]);

        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        $this->werktagsService('S', '2026-08-17', '2026-08-21');
        $this->fahrt('T1', 'S', $line->route_id, ['07:00:00', '07:20:00'], ['S1', 'S2']);
        $this->fahrt('T2', 'S', $line->route_id, ['08:00:00', '08:20:00'], ['S1', 'S2']);

        $result = $this->konsolidieren();

        $this->assertSame(2, $result['consolidated_trips']);
        $version = LineVersion::query()->where('line', '1')->sole();
        $this->assertSame(2, ConsolidatedTrip::query()->where('line_version_id', $version->id)->count());

        $fahrt = ConsolidatedTrip::query()->where('line_version_id', $version->id)->orderBy('id')->first();
        $zeiten = $fahrt->stopTimes()->orderBy('stop_sequence')->get();
        $this->assertCount(2, $zeiten);
        $this->assertSame('07:00:00', $zeiten[0]->departure_time);

        // Die Halte zeigen auf die Konsolidat-Identität, nicht auf die volatile GTFS-ID.
        $this->assertSame($fahrt->first_stop_id, $zeiten[0]->stop_id);
        $this->assertNotNull(ConsolidatedStop::query()->find($zeiten[0]->stop_id));
    }

    public function test_consolidated_schedule_survives_a_reimport_that_replaces_the_raw_data(): void
    {
        Stop::factory()->create(['stop_id' => 'S1', 'stop_name' => 'Alpha', 'lat' => 52.1400000, 'lon' => 11.6300000]);
        Stop::factory()->create(['stop_id' => 'S2', 'stop_name' => 'Beta', 'lat' => 52.1500000, 'lon' => 11.6400000]);

        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        $this->werktagsService('S', '2026-08-17', '2026-08-21');
        $this->fahrt('T1', 'S', $line->route_id, ['07:00:00', '07:20:00'], ['S1', 'S2']);
        $this->konsolidieren();

        $vorher = ConsolidatedStopTime::query()->count();
        $this->assertGreaterThan(0, $vorher);

        // Der nächste Import ersetzt den Roh-Bestand mit einem verschobenen Fenster; der alte
        // Fahrplan steht dort nicht mehr drin. Genau dafür gibt es das Konsolidat.
        StopTime::query()->delete();
        Trip::query()->delete();
        Calendar::query()->delete();
        $this->werktagsService('S-NEU', '2026-08-24', '2026-08-28');
        $this->fahrt('T-NEU', 'S-NEU', $line->route_id, ['09:00:00', '09:20:00'], ['S1', 'S2']);
        $this->konsolidieren();

        $alteVersion = LineVersion::query()->where('line', '1')->orderBy('version_no')->first();
        $this->assertSame(
            1,
            ConsolidatedTrip::query()->where('line_version_id', $alteVersion->id)->count(),
            'Der Fahrplan der ersten Version muss den Re-Import überleben',
        );

        $alteFahrt = ConsolidatedTrip::query()->where('line_version_id', $alteVersion->id)->sole();
        $this->assertSame(
            '07:00:00',
            $alteFahrt->stopTimes()->orderBy('stop_sequence')->first()->departure_time,
        );
    }

    public function test_reconsolidating_the_same_import_writes_nothing_twice(): void
    {
        Stop::factory()->create(['stop_id' => 'S1', 'stop_name' => 'Alpha', 'lat' => 52.1400000, 'lon' => 11.6300000]);
        Stop::factory()->create(['stop_id' => 'S2', 'stop_name' => 'Beta', 'lat' => 52.1500000, 'lon' => 11.6400000]);

        $line = Route::factory()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        $this->werktagsService('S', '2026-08-17', '2026-08-21');
        $this->fahrt('T1', 'S', $line->route_id, ['07:00:00', '07:20:00'], ['S1', 'S2']);

        $erst = $this->konsolidieren();
        $this->assertSame(1, $erst['consolidated_trips']);

        // Der Fingerprint deckt den Inhalt ab — eine unveränderte Version wird nicht neu
        // geschrieben.
        $zweit = $this->konsolidieren();
        $this->assertSame(0, $zweit['consolidated_trips']);
        $this->assertSame(1, ConsolidatedTrip::query()->count());
        $this->assertSame(2, ConsolidatedStopTime::query()->count());
    }

    /** Minimaler Fahrplan, damit die Konsolidierung überhaupt ein Fenster hat. */
    private function minimalerFahrplan(string $vonHalt, string $bisHalt): void
    {
        $line = Route::query()->firstOrCreate(
            ['route_id' => 'R-MIN'],
            ['route_short_name' => '99', 'route_long_name' => 'Test', 'route_type' => 0],
        );

        if (Calendar::query()->where('service_id', 'S-MIN')->doesntExist()) {
            $this->werktagsService('S-MIN', '2026-08-17', '2026-08-21');
        }

        if (Trip::query()->where('trip_id', 'T-MIN')->doesntExist()) {
            $this->fahrt('T-MIN', 'S-MIN', $line->route_id, ['07:00:00', '07:20:00'], [$vonHalt, $bisHalt]);
        }
    }
}
