<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Enums\PeriodOfferStatus;
use App\Models\Calendar;
use App\Models\CalendarDate;
use App\Models\ConsolidatedTrip;
use App\Models\LineVersion;
use App\Models\LineVersionInterval;
use App\Models\PeriodChangeOffer;
use App\Models\Route;
use App\Models\SchedulePeriod;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use App\Models\User;
use App\Services\ScheduleVersionService;
use App\Services\TripSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Periodenwechsel-Vorschlag (FAHRPLANPERIODEN §4.3): Ändern sich an einem Tag mindestens
 * 33 % der verkehrenden Linien, bietet das System einen Periodenwechsel an — legt ihn aber
 * nicht selbst an.
 */
final class PeriodChangeOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Stop::factory()->create(['stop_id' => 'A', 'stop_name' => 'Alpha']);
        Stop::factory()->create(['stop_id' => 'B', 'stop_name' => 'Beta']);
    }

    private function adminToken(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    /**
     * @param  array<int, string>  $zeiten
     */
    private function fahrt(string $tripId, string $serviceId, string $routeId, array $zeiten): void
    {
        Trip::factory()->create(['trip_id' => $tripId, 'route_id' => $routeId, 'service_id' => $serviceId]);

        foreach ($zeiten as $i => $zeit) {
            StopTime::factory()->create([
                'trip_id' => $tripId,
                'stop_id' => $i === 0 ? 'A' : 'B',
                'stop_sequence' => $i + 1,
                'departure_time' => $zeit,
                'arrival_time' => $zeit,
            ]);
        }
    }

    private function taeglichService(string $serviceId, string $von, string $bis): void
    {
        Calendar::factory()->create([
            'service_id' => $serviceId,
            'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true,
            'friday' => true, 'saturday' => true, 'sunday' => true,
            'start_date' => $von, 'end_date' => $bis,
        ]);
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

    private function konsolidieren(): void
    {
        app(TripSignatureService::class)->rebuild();
        app(ScheduleVersionService::class)->updateFromCurrentImport();
    }

    /**
     * Legt `$anzahl` Linien an; die ersten `$wechselnd` fahren ab dem 24.08. zehn Minuten
     * später, der Rest bleibt unverändert.
     */
    private function netz(int $anzahl, int $wechselnd): void
    {
        for ($i = 1; $i <= $anzahl; $i++) {
            $route = Route::factory()->create(['route_id' => "R{$i}", 'route_short_name' => (string) $i]);
            $minute = str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            if ($i <= $wechselnd) {
                $this->werktagsService("ALT{$i}", '2026-08-17', '2026-08-21');
                $this->fahrt("T-ALT{$i}", "ALT{$i}", $route->route_id, ["07:{$minute}:00", "07:{$minute}:00"]);
                $this->werktagsService("NEU{$i}", '2026-08-24', '2026-08-28');
                $this->fahrt("T-NEU{$i}", "NEU{$i}", $route->route_id, ["08:{$minute}:00", "08:{$minute}:00"]);

                continue;
            }

            $this->werktagsService("S{$i}", '2026-08-17', '2026-08-28');
            $this->fahrt("T{$i}", "S{$i}", $route->route_id, ["09:{$minute}:00", "09:{$minute}:00"]);
        }
    }

    public function test_many_lines_changing_on_the_same_day_trigger_an_offer(): void
    {
        // 4 von 10 Linien wechseln zum 24.08. → 40 %, über der Schwelle von 33 %.
        $this->netz(10, 4);
        $this->konsolidieren();

        $offer = PeriodChangeOffer::query()->sole();
        $this->assertSame('2026-08-24', $offer->suggested_from->toDateString());
        $this->assertSame(4, $offer->changed_line_count);
        $this->assertSame(10, $offer->active_line_count);
        $this->assertSame(PeriodOfferStatus::Open, $offer->status);
        $this->assertSame(['1', '2', '3', '4'], $offer->lines);

        // Vorgeschlagen, nicht angelegt: Die Periode entsteht erst durch den Admin.
        $this->assertSame(1, SchedulePeriod::query()->count());
    }

    public function test_few_lines_changing_stay_ordinary_line_versions(): void
    {
        // 2 von 10 Linien → 20 %, unter der Schwelle: eine Baustelle, kein Fahrplanwechsel.
        $this->netz(10, 2);
        $this->konsolidieren();

        $this->assertDatabaseCount('period_change_offers', 0);
        $this->assertSame(12, LineVersion::query()->count(), '10 Linien, zwei davon mit zweiter Version');
    }

    public function test_first_import_does_not_offer_a_change_at_the_window_edge(): void
    {
        // Alle Linien beginnen an der Fensterkante — das ist keine beobachtete Änderung,
        // sondern nur eine Untergrenze (§5.4 b).
        $this->netz(10, 0);
        $this->konsolidieren();

        $this->assertDatabaseCount('period_change_offers', 0);
    }

    public function test_accepting_creates_the_period_and_restarts_version_numbering(): void
    {
        $token = $this->adminToken();
        $this->netz(10, 4);
        $this->konsolidieren();

        $offer = PeriodChangeOffer::query()->sole();

        $this->withToken($token)
            ->postJson("/api/v1/admin/period-change-offers/{$offer->id}/accept", [
                'label' => 'Jahresfahrplan 2026/27',
            ])
            ->assertCreated()
            ->assertJsonPath('data.label', 'Jahresfahrplan 2026/27')
            ->assertJsonPath('data.valid_from', '2026-08-24')
            ->assertJsonPath('data.created_via', 'admin');

        $this->assertSame(PeriodOfferStatus::Accepted, $offer->refresh()->status);
        $this->assertNotNull($offer->decided_at);

        $alt = SchedulePeriod::query()->whereDate('valid_from', '<', '2026-08-24')->sole();
        $neu = SchedulePeriod::query()->whereDate('valid_from', '2026-08-24')->sole();

        // Die alte Periode bleibt frei von Versions-Wildwuchs: je Linie genau eine Version,
        // die zweite ist in den Periodenwechsel aufgegangen (§4.3).
        $this->assertSame(10, LineVersion::query()->where('period_id', $alt->id)->count());
        $this->assertSame(
            [1],
            LineVersion::query()->where('period_id', $alt->id)->pluck('version_no')->unique()->values()->all(),
        );

        // In der neuen Periode fängt jede Linie wieder bei 1 an.
        $this->assertSame(10, LineVersion::query()->where('period_id', $neu->id)->count());
        $this->assertSame(
            [1],
            LineVersion::query()->where('period_id', $neu->id)->pluck('version_no')->unique()->values()->all(),
        );

        // Die Gültigkeit der Vorperiode endet am Vortag des Wechsels.
        $letztes = LineVersion::query()->where('period_id', $alt->id)->where('line', '1')->sole()
            ->intervals()->orderByDesc('valid_to')->first();
        $this->assertSame('2026-08-21', $letztes->valid_to->toDateString());
    }

    public function test_declining_keeps_the_line_versions_in_the_running_period(): void
    {
        $token = $this->adminToken();
        $this->netz(10, 4);
        $this->konsolidieren();

        $offer = PeriodChangeOffer::query()->sole();
        $vorher = LineVersion::query()->count();

        $this->withToken($token)
            ->postJson("/api/v1/admin/period-change-offers/{$offer->id}/decline")
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');

        // Abgelehnt heißt „war kein Fahrplanwechsel", nicht „war nichts": Die Versionen
        // bleiben unangetastet in der laufenden Periode stehen.
        $this->assertSame($vorher, LineVersion::query()->count());
        $this->assertSame(1, SchedulePeriod::query()->count());
    }

    public function test_a_decided_offer_is_neither_repeated_nor_decided_twice(): void
    {
        $token = $this->adminToken();
        $this->netz(10, 4);
        $this->konsolidieren();

        $offer = PeriodChangeOffer::query()->sole();
        $this->withToken($token)->postJson("/api/v1/admin/period-change-offers/{$offer->id}/decline")->assertOk();

        // Ein Folge-Import darf denselben Tag nicht erneut vorschlagen.
        $this->konsolidieren();
        $this->assertDatabaseCount('period_change_offers', 1);

        $this->withToken($token)
            ->postJson("/api/v1/admin/period-change-offers/{$offer->id}/accept", ['label' => 'Zu spät'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 409);
    }

    public function test_a_change_on_the_last_observed_day_is_marked_as_a_single_observation(): void
    {
        // 4 von 10 Linien wechseln am 27.08. — dem letzten ausgewerteten Tag des Fensters
        // (der 28.08. faellt als Fensterrand heraus, §10). Dahinter reichen die Daten nicht;
        // ein Rand ist kein Fahrplanwechsel (§5.4 b).
        for ($i = 1; $i <= 10; $i++) {
            $route = Route::factory()->create(['route_id' => "R{$i}", 'route_short_name' => (string) $i]);
            $minute = str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            if ($i <= 4) {
                $this->werktagsService("ALT{$i}", '2026-08-17', '2026-08-26');
                $this->fahrt("T-ALT{$i}", "ALT{$i}", $route->route_id, ["07:{$minute}:00", "07:{$minute}:00"]);
                $this->werktagsService("NEU{$i}", '2026-08-27', '2026-08-27');
                $this->fahrt("T-NEU{$i}", "NEU{$i}", $route->route_id, ["08:{$minute}:00", "08:{$minute}:00"]);

                continue;
            }

            $this->werktagsService("S{$i}", '2026-08-17', '2026-08-28');
            $this->fahrt("T{$i}", "S{$i}", $route->route_id, ["09:{$minute}:00", "09:{$minute}:00"]);
        }

        $this->konsolidieren();

        $this->withToken($this->adminToken())
            ->getJson('/api/v1/admin/period-change-offers')
            ->assertOk()
            ->assertJsonPath('data.0.suggested_from', '2026-08-27')
            ->assertJsonPath('data.0.observed_until', '2026-08-27')
            ->assertJsonPath('data.0.single_day_observation', true);
    }

    public function test_the_last_day_of_the_feed_window_is_not_observed_at_all(): void
    {
        // Derselbe Aufbau einen Tag spaeter: Der Wechsel faellt auf den 28.08., den letzten
        // Tag des Fensters. Dessen Betriebstag endet erst in der Nacht auf den 29.08., und
        // die steht im Feed unter einem Tag, den es nicht gibt — der Tag ist nur zur Haelfte
        // beobachtbar und wird gar nicht erst ausgewertet (§10).
        for ($i = 1; $i <= 10; $i++) {
            $route = Route::factory()->create(['route_id' => "R{$i}", 'route_short_name' => (string) $i]);
            $minute = str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            if ($i <= 4) {
                $this->werktagsService("ALT{$i}", '2026-08-17', '2026-08-27');
                $this->fahrt("T-ALT{$i}", "ALT{$i}", $route->route_id, ["07:{$minute}:00", "07:{$minute}:00"]);
                $this->werktagsService("NEU{$i}", '2026-08-28', '2026-08-28');
                $this->fahrt("T-NEU{$i}", "NEU{$i}", $route->route_id, ["08:{$minute}:00", "08:{$minute}:00"]);

                continue;
            }

            $this->werktagsService("S{$i}", '2026-08-17', '2026-08-28');
            $this->fahrt("T{$i}", "S{$i}", $route->route_id, ["09:{$minute}:00", "09:{$minute}:00"]);
        }

        $this->konsolidieren();

        $this->assertDatabaseCount('period_change_offers', 0);
        $this->assertSame(10, LineVersion::query()->count(), 'Der Fahrplan des 28.08. wird nicht beobachtet');

        // Die Beobachtung endet am Vortag — und offen, denn der naechste Import holt den Tag
        // als Innentag nach.
        $letztes = LineVersion::query()->where('line', '1')->sole()
            ->intervals()->orderByDesc('valid_to')->first();
        $this->assertSame('2026-08-27', $letztes->valid_to->toDateString());
        $this->assertFalse($letztes->to_confirmed);
    }

    public function test_a_night_line_keeps_its_version_across_the_window_edge(): void
    {
        // Der reale Fall vom 16.10.2026: Eine Nachtlinie faehrt ihre Nacht im Feed unter dem
        // Folgetag. Am letzten Fenstertag fehlt diese Nacht — wertete man den Tag aus, saehe
        // jede Nachtlinie dort einen anderen Fahrplan, und neun davon reichten aus, um einen
        // Fahrplanwechsel vorzutaeuschen, den es nicht gibt.
        for ($i = 1; $i <= 10; $i++) {
            $route = Route::factory()->create(['route_id' => "R{$i}", 'route_short_name' => "N{$i}"]);
            $minute = str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            $this->taeglichService("S{$i}", '2026-08-17', '2026-08-28');
            $this->fahrt("T-ABEND{$i}", "S{$i}", $route->route_id, ["22:{$minute}:00", "22:{$minute}:00"]);
            // Vor der Nachtlinien-Grenze (12:00): gehoert zum Betriebstag des Vortags.
            $this->fahrt("T-NACHT{$i}", "S{$i}", $route->route_id, ["02:{$minute}:00", "02:{$minute}:00"]);
        }

        $this->konsolidieren();

        $this->assertDatabaseCount('period_change_offers', 0);
        $this->assertSame(
            10,
            LineVersion::query()->where('day_type', FahrplanTyp::MoFrNormal)->count(),
            'Je Nachtlinie genau eine Mo-Fr-Version — der Abendteil des Fensterrands bildet keine zweite',
        );
    }

    /**
     * Legt `$anzahl` Linien an; die ersten `$wechselnd` fahren an den Tagen `$sondertage`
     * nach einem anderen Fahrplan und danach wieder nach dem alten.
     *
     * @param  array<int, string>  $sondertage
     */
    private function netzMitSonderverkehr(int $anzahl, int $wechselnd, array $sondertage): void
    {
        for ($i = 1; $i <= $anzahl; $i++) {
            $route = Route::factory()->create(['route_id' => "R{$i}", 'route_short_name' => (string) $i]);
            $minute = str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            $this->werktagsService("S{$i}", '2026-08-17', '2026-08-28');
            $this->fahrt("T{$i}", "S{$i}", $route->route_id, ["07:{$minute}:00", "07:{$minute}:00"]);

            if ($i > $wechselnd) {
                continue;
            }

            // Ohne Wochenmuster — dieser Service faehrt ausschliesslich an den Sondertagen.
            Calendar::factory()->create([
                'service_id' => "SONDER{$i}",
                'monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false,
                'friday' => false, 'saturday' => false, 'sunday' => false,
                'start_date' => '2026-08-17', 'end_date' => '2026-08-28',
            ]);
            $this->fahrt("T-SONDER{$i}", "SONDER{$i}", $route->route_id, ["08:{$minute}:00", "08:{$minute}:00"]);

            // Der Regelverkehr entfaellt an den Sondertagen, der Sonderverkehr faehrt nur dort.
            foreach ($sondertage as $tag) {
                CalendarDate::factory()->create(['service_id' => "S{$i}", 'date' => $tag, 'exception_type' => 2]);
                CalendarDate::factory()->create(['service_id' => "SONDER{$i}", 'date' => $tag, 'exception_type' => 1]);
            }
        }
    }

    public function test_a_one_day_deviation_that_reverts_is_no_period_change(): void
    {
        // Der reale Fall vom 03.10.2026: ein Feiertag, an dem 10 von 30 Linien anders fahren
        // — und am Tag darauf wieder wie zuvor. Anteilig ueber der Schwelle, fachlich aber
        // kein Fahrplanwechsel: Ein Wechsel bleibt, eine Abweichung kehrt zurueck (§4.3).
        $this->netzMitSonderverkehr(10, 5, ['2026-08-24']);
        $this->konsolidieren();

        $this->assertDatabaseCount('period_change_offers', 0);

        // Die Abweichung selbst bleibt als Version erhalten — auch ein einzelner Tag ist ein
        // Fahrplanstand (§5.4). Unterdrueckt wird nur der Vorschlag.
        $this->assertSame(15, LineVersion::query()->count(), '10 Linien, fuenf davon mit Sonderfahrplan');

        $intervalle = LineVersion::query()->where('line', '1')->orderBy('version_no')->get()
            ->flatMap(fn (LineVersion $v): array => $v->intervals()->orderBy('valid_from')->get()
                ->map(fn ($iv): string => $iv->valid_from->toDateString().'..'.$iv->valid_to->toDateString())->all())
            ->all();

        // Der alte Fahrplan kehrt zurueck: derselbe Fingerprint, dieselbe Version, ein
        // zweites Intervall.
        $this->assertSame(['2026-08-17..2026-08-21', '2026-08-25..2026-08-27', '2026-08-24..2026-08-24'], $intervalle);
    }

    public function test_a_multi_day_deviation_that_reverts_is_no_period_change_either(): void
    {
        // Dieselbe Regel traegt laenger: Ein Ersatzverkehr ueber drei Tage ist kein
        // Fahrplanwechsel, solange der alte Fahrplan danach zurueckkehrt.
        $this->netzMitSonderverkehr(10, 5, ['2026-08-24', '2026-08-25', '2026-08-26']);
        $this->konsolidieren();

        $this->assertDatabaseCount('period_change_offers', 0);
    }

    public function test_a_deviation_that_does_not_revert_is_still_offered(): void
    {
        // Gegenprobe: Kehrt der alte Fahrplan nicht zurueck, bleibt es ein Wechsel — die
        // Regel darf den Normalfall nicht mit unterdruecken.
        $this->netzMitSonderverkehr(10, 5, ['2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27']);
        $this->konsolidieren();

        $offer = PeriodChangeOffer::query()->sole();
        $this->assertSame('2026-08-24', $offer->suggested_from->toDateString());
        $this->assertSame(['1', '2', '3', '4', '5'], $offer->lines);
    }

    public function test_the_evidence_counts_only_the_lines_of_the_offer(): void
    {
        // Realer Fall vom 03.10.2026: Der Vorschlag umfasst die Linien 1 und 2, beide mit
        // genau einem beobachteten Tag. Linie 9 wechselt am selben Tag und bleibt dabei —
        // sie gehoert aber nicht zum Vorschlag und darf ihm keine Beleglage leihen, die
        // keine seiner eigenen Linien hat. Der Zustand wird direkt aufgebaut: Er entsteht
        // im Betrieb erst ueber zwei Importe, denn ein Tag wird nur einmal vorgeschlagen.
        $f = new ConsolidatedFixtures;
        $periode = $f->periode();

        PeriodChangeOffer::query()->create([
            'suggested_from' => '2026-08-24',
            'changed_line_count' => 2,
            'active_line_count' => 3,
            'lines' => ['1', '2'],
            'status' => PeriodOfferStatus::Open,
        ]);

        foreach (['1', '2'] as $line) {
            LineVersionInterval::factory()->create([
                'line_version_id' => $f->version($line, FahrplanTyp::MoFrNormal, 2, $periode)->id,
                'valid_from' => '2026-08-24',
                'valid_to' => '2026-08-24',
            ]);
        }

        LineVersionInterval::factory()->create([
            'line_version_id' => $f->version('9', FahrplanTyp::MoFrNormal, 2, $periode)->id,
            'valid_from' => '2026-08-24',
            'valid_to' => '2026-09-04',
        ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/v1/admin/period-change-offers')
            ->assertOk()
            ->assertJsonPath('data.0.suggested_from', '2026-08-24')
            ->assertJsonPath('data.0.observed_until', '2026-08-24')
            ->assertJsonPath('data.0.single_day_observation', true);
    }

    public function test_a_change_confirmed_beyond_its_first_day_is_not_flagged(): void
    {
        // Hier laeuft der neue Fahrplan noch eine Woche weiter — die Beobachtung traegt.
        $this->netz(10, 4);
        $this->konsolidieren();

        $this->withToken($this->adminToken())
            ->getJson('/api/v1/admin/period-change-offers')
            ->assertOk()
            ->assertJsonPath('data.0.suggested_from', '2026-08-24')
            ->assertJsonPath('data.0.observed_until', '2026-08-27')
            ->assertJsonPath('data.0.single_day_observation', false);
    }

    public function test_accepting_keeps_a_version_that_lost_its_validity_to_displacement(): void
    {
        // Eine Version kann ihre Gueltigkeit auch ohne Periodenwechsel verlieren: Ein spaeterer
        // Lauf sah an denselben Tagen einen anderen Fahrplan und hat sie verdraengt (§5.3). Sie
        // haelt fest, was der Feed einmal behauptet hat, und bleibt erhalten — der Rollback darf
        // sie nicht mitnehmen, sonst haengen ueber cascadeOnDelete auch ihre Fahrten daran.
        $token = $this->adminToken();
        $this->netz(10, 4);
        $this->konsolidieren();

        $offer = PeriodChangeOffer::query()->sole();

        $f = new ConsolidatedFixtures;
        $verdraengt = $f->version('99', FahrplanTyp::MoFrNormal, 1, SchedulePeriod::query()->sole());
        $f->fahrt($verdraengt, ['A', 'B'], ['07:00:00', '07:10:00']);

        $this->assertSame(0, $verdraengt->intervals()->count(), 'Aufbau: ohne Gueltigkeit');

        $this->withToken($token)
            ->postJson("/api/v1/admin/period-change-offers/{$offer->id}/accept", ['label' => 'Neu'])
            ->assertCreated();

        $this->assertDatabaseHas('line_versions', ['id' => $verdraengt->id]);
        $this->assertSame(
            1,
            ConsolidatedTrip::query()->where('line_version_id', $verdraengt->id)->count(),
            'Die Fahrten der verdraengten Version bleiben abrufbar',
        );
    }

    public function test_open_offers_are_listed_and_require_auth(): void
    {
        $this->getJson('/api/v1/admin/period-change-offers')->assertStatus(401);

        $this->netz(10, 4);
        $this->konsolidieren();

        $this->withToken($this->adminToken())
            ->getJson('/api/v1/admin/period-change-offers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.suggested_from', '2026-08-24')
            ->assertJsonPath('data.0.changed_line_count', 4)
            ->assertJsonPath('data.0.share', 0.4);
    }
}
