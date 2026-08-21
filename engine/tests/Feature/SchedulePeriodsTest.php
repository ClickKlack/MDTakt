<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Enums\PeriodOrigin;
use App\Enums\PeriodStatus;
use App\Models\LineVersion;
use App\Models\SchedulePeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin-CRUD der netzweiten Fahrplanperioden (FAHRPLANPERIODEN §4.1).
 */
final class SchedulePeriodsTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    private function periode(string $label, string $validFrom, PeriodOrigin $origin = PeriodOrigin::Admin): SchedulePeriod
    {
        return SchedulePeriod::query()->create([
            'label' => $label,
            'valid_from' => $validFrom,
            'valid_to' => null,
            'status' => PeriodStatus::Frozen,
            'created_via' => $origin,
        ]);
    }

    public function test_listing_requires_auth(): void
    {
        $this->getJson('/api/v1/admin/schedule-periods')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_admin_can_create_a_period_and_the_chain_closes_the_predecessor(): void
    {
        $token = $this->adminToken();
        $alt = $this->periode('Ausgangsperiode', '2026-08-15', PeriodOrigin::Bootstrap);

        $this->withToken($token)->postJson('/api/v1/admin/schedule-periods', [
            'label' => 'Jahresfahrplan 2026/27',
            'valid_from' => '2026-12-13',
        ])
            ->assertCreated()
            ->assertJsonPath('data.label', 'Jahresfahrplan 2026/27')
            ->assertJsonPath('data.created_via', 'admin')
            ->assertJsonPath('data.valid_to', null)
            ->assertJsonPath('data.line_version_count', 0)
            ->assertJsonPath('data.is_deletable', true);

        // Der Vorgänger endet am Vortag — valid_to ist abgeleitet, nicht eingegeben.
        $this->assertSame('2026-12-12', $alt->refresh()->valid_to?->toDateString());
    }

    public function test_two_periods_cannot_start_on_the_same_day(): void
    {
        $token = $this->adminToken();
        $this->periode('Erste', '2026-12-13');

        $this->withToken($token)->postJson('/api/v1/admin/schedule-periods', [
            'label' => 'Zweite',
            'valid_from' => '2026-12-13',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_current_is_the_period_covering_today_a_future_one_stays_frozen(): void
    {
        $token = $this->adminToken();
        $heute = CarbonImmutable::now();

        $laufend = $this->periode('Laufend', $heute->subMonths(2)->toDateString());
        $kuenftig = $this->periode('Künftig', $heute->addMonths(2)->toDateString());

        // Der Kettenabgleich läuft bei jeder Änderung — hier über das Anlegen einer dritten.
        $this->withToken($token)->postJson('/api/v1/admin/schedule-periods', [
            'label' => 'Noch später',
            'valid_from' => $heute->addMonths(5)->toDateString(),
        ])->assertCreated();

        $this->assertSame(PeriodStatus::Current, $laufend->refresh()->status);

        // Eine erst künftig beginnende Periode darf nicht `current` sein — sonst schlüge der
        // nächste Import seine Versionen einer noch nicht geltenden Periode zu.
        $this->assertSame(PeriodStatus::Frozen, $kuenftig->refresh()->status);
    }

    public function test_moving_a_period_start_drags_the_neighbours_validity_along(): void
    {
        $token = $this->adminToken();
        $alt = $this->periode('Ausgangsperiode', '2026-08-15');
        $neu = $this->periode('Jahresfahrplan', '2026-12-13');

        $this->withToken($token)->putJson("/api/v1/admin/schedule-periods/{$neu->id}", [
            'label' => 'Jahresfahrplan 2026/27',
            'valid_from' => '2026-12-06',
        ])
            ->assertOk()
            ->assertJsonPath('data.valid_from', '2026-12-06');

        $this->assertSame('2026-12-05', $alt->refresh()->valid_to?->toDateString());
    }

    public function test_period_with_line_versions_cannot_be_deleted(): void
    {
        $token = $this->adminToken();
        $periode = $this->periode('Ausgangsperiode', '2026-08-15');

        LineVersion::query()->create([
            'period_id' => $periode->id,
            'line' => '1',
            'day_type' => FahrplanTyp::MoFrNormal,
            'version_no' => 1,
            'fingerprint' => str_repeat('a', 64),
            'first_seen_at' => CarbonImmutable::now(),
            'last_seen_at' => CarbonImmutable::now(),
        ]);

        $this->withToken($token)->deleteJson("/api/v1/admin/schedule-periods/{$periode->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 409);

        // Beobachtete Fahrplan-Historie ist nicht wiederbeschaffbar — sie muss stehen bleiben.
        $this->assertDatabaseCount('line_versions', 1);
        $this->assertDatabaseHas('schedule_periods', ['id' => $periode->id]);
    }

    public function test_empty_period_can_be_deleted_and_the_predecessor_reopens(): void
    {
        $token = $this->adminToken();
        $alt = $this->periode('Ausgangsperiode', '2026-08-15');
        $neu = $this->periode('Versehentlich angelegt', '2026-12-13');

        // Kette aufbauen, damit der Vorgänger vor dem Löschen wirklich geschlossen ist.
        $this->withToken($token)->putJson("/api/v1/admin/schedule-periods/{$alt->id}", [
            'label' => 'Ausgangsperiode',
            'valid_from' => '2026-08-15',
        ])->assertOk();
        $this->assertSame('2026-12-12', $alt->refresh()->valid_to?->toDateString());

        $this->withToken($token)->deleteJson("/api/v1/admin/schedule-periods/{$neu->id}")
            ->assertNoContent();

        // Nach dem Löschen übernimmt der Vorgänger den freigewordenen Zeitraum.
        $this->assertNull($alt->refresh()->valid_to);
        $this->assertSame(PeriodStatus::Current, $alt->refresh()->status);
    }

    public function test_list_is_sorted_newest_first_and_reports_attached_versions(): void
    {
        $token = $this->adminToken();
        $alt = $this->periode('Ausgangsperiode', '2026-08-15');
        $this->periode('Jahresfahrplan', '2026-12-13');

        LineVersion::query()->create([
            'period_id' => $alt->id,
            'line' => '1',
            'day_type' => FahrplanTyp::MoFrNormal,
            'version_no' => 1,
            'fingerprint' => str_repeat('b', 64),
            'first_seen_at' => CarbonImmutable::now(),
            'last_seen_at' => CarbonImmutable::now(),
        ]);

        $this->withToken($token)->getJson('/api/v1/admin/schedule-periods')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.label', 'Jahresfahrplan')
            ->assertJsonPath('data.1.label', 'Ausgangsperiode')
            ->assertJsonPath('data.1.line_version_count', 1)
            ->assertJsonPath('data.1.is_deletable', false);
    }
}
