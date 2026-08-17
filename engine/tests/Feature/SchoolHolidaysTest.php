<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SchoolHolidaysTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    public function test_listing_requires_auth(): void
    {
        $this->getJson('/api/v1/admin/school-holidays')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_admin_can_crud_school_holidays(): void
    {
        $token = $this->adminToken();

        // anlegen
        $id = $this->withToken($token)->postJson('/api/v1/admin/school-holidays', [
            'name' => 'Sommerferien 2026',
            'start_date' => '2026-07-13',
            'end_date' => '2026-08-16',
        ])->assertCreated()->assertJsonPath('data.name', 'Sommerferien 2026')->json('data.id');

        // auflisten
        $this->withToken($token)->getJson('/api/v1/admin/school-holidays')
            ->assertOk()->assertJsonCount(1, 'data');

        // ändern
        $this->withToken($token)->putJson("/api/v1/admin/school-holidays/{$id}", [
            'name' => 'Sommerferien',
            'start_date' => '2026-07-13',
            'end_date' => '2026-08-25',
        ])->assertOk()->assertJsonPath('data.end_date', '2026-08-25');

        // löschen
        $this->withToken($token)->deleteJson("/api/v1/admin/school-holidays/{$id}")->assertNoContent();
        $this->assertDatabaseCount('school_holidays', 0);
    }

    public function test_end_before_start_is_rejected(): void
    {
        $this->withToken($this->adminToken())->postJson('/api/v1/admin/school-holidays', [
            'name' => 'Falsch',
            'start_date' => '2026-08-16',
            'end_date' => '2026-07-13',
        ])->assertStatus(422)->assertJsonPath('error.code', 422);
    }

    public function test_holidays_endpoint_returns_computed_days(): void
    {
        $response = $this->withToken($this->adminToken())->getJson('/api/v1/admin/holidays?year=2026');

        $response->assertOk()->assertJsonPath('data.year', 2026);
        $dates = collect($response->json('data.holidays'))->pluck('date');
        $this->assertContains('2026-10-31', $dates->all()); // Reformationstag
        $this->assertContains('2026-04-03', $dates->all()); // Karfreitag
    }
}
