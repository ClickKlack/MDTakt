<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Enums\PeriodOfferStatus;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\CourseTrip;
use App\Models\LineVersion;
use App\Models\LineVersionInterval;
use App\Models\PeriodChangeOffer;
use App\Models\SchedulePeriod;
use App\Services\ScheduleRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Nachtraegliche Bereinigung der Artefakte, die die alte Rand- und Wechsel-Erkennung
 * hinterlassen hat (FAHRPLANPERIODEN §4.3, §10).
 *
 * Der Zustand wird direkt aufgebaut: Mit den korrigierten Regeln laesst er sich gar nicht
 * mehr erzeugen — genau das ist der Grund, warum es die Bereinigung ueberhaupt braucht.
 */
final class ScheduleRepairTest extends TestCase
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

    /**
     * Ein Feed-Fenster, damit der Dienst den halb beobachteten Tag von selbst findet.
     */
    private function feedFenster(string $von, string $bis): void
    {
        Calendar::factory()->create([
            'service_id' => 'W',
            'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true,
            'friday' => true, 'saturday' => true, 'sunday' => true,
            'start_date' => $von, 'end_date' => $bis,
        ]);
    }

    private function intervall(LineVersion $version, string $von, string $bis, bool $vonBestaetigt = true, bool $bisBestaetigt = false): LineVersionInterval
    {
        return LineVersionInterval::factory()->create([
            'line_version_id' => $version->id,
            'valid_from' => $von,
            'valid_to' => $bis,
            'from_confirmed' => $vonBestaetigt,
            'to_confirmed' => $bisBestaetigt,
        ]);
    }

    public function test_the_half_observed_day_is_withdrawn_and_the_boundary_reopened(): void
    {
        $this->feedFenster('2026-09-19', '2026-10-16');

        $echt = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $this->periode);
        $phantom = $this->f->version('1', FahrplanTyp::MoFrNormal, 2, $this->periode);

        // Die Grenze gilt als gesichert — weil dahinter der Phantomtag lag.
        $vorher = $this->intervall($echt, '2026-09-21', '2026-10-15', false, true);
        $this->intervall($phantom, '2026-10-16', '2026-10-16');

        $bericht = app(ScheduleRepairService::class)->repair();

        $this->assertSame('2026-10-16', $bericht['day']);
        $this->assertSame(1, $bericht['intervals_removed']);
        $this->assertSame(1, $bericht['versions_removed']);
        $this->assertSame(1, $bericht['boundaries_reopened']);

        $this->assertDatabaseMissing('line_versions', ['id' => $phantom->id]);
        $this->assertDatabaseHas('line_versions', ['id' => $echt->id]);

        // Ohne den Phantomtag ist das Ende wieder eine blosse Untergrenze (§5.4 b).
        $this->assertFalse($vorher->refresh()->to_confirmed);
        $this->assertSame('2026-10-15', $vorher->valid_to->toDateString());
    }

    public function test_a_version_that_keeps_validity_survives_with_its_trips(): void
    {
        $this->feedFenster('2026-09-19', '2026-10-16');

        // Dieselbe Version traegt zwei Intervalle — nur das des Phantomtags faellt weg.
        $version = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $this->periode);
        $this->intervall($version, '2026-09-21', '2026-10-15');
        $this->intervall($version, '2026-10-16', '2026-10-16');

        $fahrt = $this->f->fahrt($version, ['A', 'B'], ['07:00:00', '07:10:00']);
        $kurs = Course::factory()->create(['period_id' => $this->periode->id, 'day_type' => FahrplanTyp::MoFrNormal, 'number' => '01']);
        CourseTrip::factory()->create(['course_id' => $kurs->id, 'consolidated_trip_id' => $fahrt->id]);

        app(ScheduleRepairService::class)->repair();

        $this->assertDatabaseHas('line_versions', ['id' => $version->id]);
        $this->assertSame(1, $version->intervals()->count());

        // Die gepflegte Zuordnung darf die Bereinigung nicht kosten — daran haengt die
        // gesamte Kurspflege (`course_trips` kaskadiert ueber `consolidated_trips`).
        $this->assertDatabaseHas('course_trips', ['consolidated_trip_id' => $fahrt->id]);
    }

    public function test_an_offer_without_any_observed_change_is_withdrawn(): void
    {
        $this->feedFenster('2026-09-19', '2026-10-16');

        PeriodChangeOffer::query()->create([
            'suggested_from' => '2026-10-16',
            'changed_line_count' => 1,
            'active_line_count' => 2,
            'lines' => ['1'],
            'status' => PeriodOfferStatus::Open,
        ]);

        $phantom = $this->f->version('1', FahrplanTyp::MoFrNormal, 2, $this->periode);
        $this->intervall($phantom, '2026-10-16', '2026-10-16');

        $bericht = app(ScheduleRepairService::class)->repair();

        $this->assertSame(['2026-10-16'], $bericht['offers_withdrawn']);

        // Geloescht, nicht abgelehnt: Der Tag war nie geprueft, sondern nie beobachtet. Ein
        // spaeterer Import, der ihn im Inneren sieht, darf ihn erneut vorschlagen.
        $this->assertDatabaseCount('period_change_offers', 0);
    }

    public function test_an_offer_whose_deviation_reverts_is_withdrawn(): void
    {
        // Der Feiertagsfall vom 03.10.2026: Die Abweichung gilt einen Tag, danach laeuft die
        // Vorgaengerversion weiter.
        PeriodChangeOffer::query()->create([
            'suggested_from' => '2026-10-03',
            'changed_line_count' => 1,
            'active_line_count' => 2,
            'lines' => ['1'],
            'status' => PeriodOfferStatus::Open,
        ]);

        $regel = $this->f->version('1', FahrplanTyp::SoFeiertag, 1, $this->periode);
        $feiertag = $this->f->version('1', FahrplanTyp::SoFeiertag, 2, $this->periode);

        $this->intervall($regel, '2026-09-27', '2026-09-27', true, true);
        $this->intervall($feiertag, '2026-10-03', '2026-10-03', true, true);
        $this->intervall($regel, '2026-10-04', '2026-10-11');

        $bericht = app(ScheduleRepairService::class)->repair('2026-10-16');

        $this->assertSame(['2026-10-03'], $bericht['offers_withdrawn']);

        // Die Abweichung selbst bleibt — auch ein einzelner Tag ist ein Fahrplanstand (§5.4).
        $this->assertDatabaseHas('line_versions', ['id' => $feiertag->id]);
        $this->assertSame(1, $feiertag->intervals()->count());
    }

    public function test_a_real_change_keeps_its_offer(): void
    {
        // Gegenprobe: Der neue Fahrplan bleibt — kein Vorgaenger kehrt zurueck.
        PeriodChangeOffer::query()->create([
            'suggested_from' => '2026-09-21',
            'changed_line_count' => 1,
            'active_line_count' => 2,
            'lines' => ['1'],
            'status' => PeriodOfferStatus::Open,
        ]);

        $alt = $this->f->version('1', FahrplanTyp::MoFrNormal, 1, $this->periode);
        $neu = $this->f->version('1', FahrplanTyp::MoFrNormal, 2, $this->periode);
        $this->intervall($alt, '2026-09-07', '2026-09-18', false, true);
        $this->intervall($neu, '2026-09-21', '2026-10-15');

        $bericht = app(ScheduleRepairService::class)->repair('2026-10-16');

        $this->assertSame([], $bericht['offers_withdrawn']);
        $this->assertDatabaseCount('period_change_offers', 1);
    }

    public function test_decided_offers_are_never_touched(): void
    {
        foreach ([PeriodOfferStatus::Accepted, PeriodOfferStatus::Declined] as $i => $status) {
            PeriodChangeOffer::query()->create([
                'suggested_from' => '2026-09-0'.($i + 1),
                'changed_line_count' => 1,
                'active_line_count' => 2,
                'lines' => ['1'],
                'status' => $status,
            ]);
        }

        app(ScheduleRepairService::class)->repair('2026-10-16');

        // In einem beschiedenen Vorschlag steckt eine Entscheidung des Admins.
        $this->assertDatabaseCount('period_change_offers', 2);
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $this->feedFenster('2026-09-19', '2026-10-16');

        $phantom = $this->f->version('1', FahrplanTyp::MoFrNormal, 2, $this->periode);
        $this->intervall($phantom, '2026-10-16', '2026-10-16');

        $bericht = app(ScheduleRepairService::class)->repair(null, true);

        $this->assertTrue($bericht['dry_run']);
        $this->assertSame(1, $bericht['intervals_removed'], 'Der Probelauf berichtet, was ein echter Lauf taete');
        $this->assertDatabaseHas('line_versions', ['id' => $phantom->id]);
        $this->assertDatabaseCount('line_version_intervals', 1);
    }

    public function test_a_second_run_finds_nothing_left_to_do(): void
    {
        $this->feedFenster('2026-09-19', '2026-10-16');

        $phantom = $this->f->version('1', FahrplanTyp::MoFrNormal, 2, $this->periode);
        $this->intervall($phantom, '2026-10-16', '2026-10-16');

        app(ScheduleRepairService::class)->repair();
        $bericht = app(ScheduleRepairService::class)->repair();

        $this->assertSame(0, $bericht['intervals_removed']);
        $this->assertSame(0, $bericht['versions_removed']);
        $this->assertSame([], $bericht['offers_withdrawn']);
    }

    public function test_the_command_runs_and_reports(): void
    {
        $this->feedFenster('2026-09-19', '2026-10-16');

        $phantom = $this->f->version('1', FahrplanTyp::MoFrNormal, 2, $this->periode);
        $this->intervall($phantom, '2026-10-16', '2026-10-16');

        $this->artisan('schedule:repair-artifacts', ['--dry-run' => true])
            ->expectsOutputToContain('2026-10-16')
            ->assertSuccessful();

        // Der Probelauf schreibt nicht.
        $this->assertDatabaseHas('line_versions', ['id' => $phantom->id]);

        $this->artisan('schedule:repair-artifacts')->assertSuccessful();
        $this->assertDatabaseMissing('line_versions', ['id' => $phantom->id]);
    }

    public function test_the_command_rejects_a_malformed_day(): void
    {
        $this->artisan('schedule:repair-artifacts', ['--day' => '16.10.2026'])->assertFailed();
    }
}
