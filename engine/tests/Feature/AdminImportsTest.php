<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GtfsImportStatus;
use App\Models\GtfsImportRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class AdminImportsTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    public function test_imports_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/admin/imports')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_imports_endpoint_returns_history_and_current_state(): void
    {
        GtfsImportRun::create([
            'status' => GtfsImportStatus::Success,
            'started_at' => Carbon::parse('2026-06-20 01:00:00'),
            'finished_at' => Carbon::parse('2026-06-20 01:05:00'),
            'feed_version' => '2026-06-20T03:00',
            'feed_start_date' => '2026-06-20',
            'feed_end_date' => '2026-12-13',
            'counts' => ['routes' => 3, 'stops' => 5, 'trips' => 10, 'stop_times' => 40, 'calendar' => 2, 'calendar_dates' => 1],
        ]);

        $response = $this->withToken($this->adminToken())->getJson('/api/v1/admin/imports');

        $response->assertOk()
            ->assertJsonPath('data.current.feed_version', '2026-06-20T03:00')
            ->assertJsonPath('data.current.feed_start_date', '2026-06-20')
            ->assertJsonPath('data.current.feed_end_date', '2026-12-13')
            ->assertJsonCount(1, 'data.runs')
            ->assertJsonPath('data.runs.0.status', 'success')
            ->assertJsonPath('data.pagination.per_page', 10)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.pagination.last_page', 1);

        $this->assertIsInt($response->json('data.current.tables.routes'));
        $this->assertIsInt($response->json('data.current.tables.calendar'));
    }

    public function test_imports_history_is_paginated_ten_per_page(): void
    {
        // 12 Läufe anlegen — neueste zuerst, 10 pro Seite.
        for ($i = 1; $i <= 12; $i++) {
            GtfsImportRun::create([
                'status' => GtfsImportStatus::Success,
                'started_at' => Carbon::parse('2026-06-20 00:00:00')->addMinutes($i),
            ]);
        }

        $token = $this->adminToken();

        $page1 = $this->withToken($token)->getJson('/api/v1/admin/imports');
        $page1->assertOk()
            ->assertJsonCount(10, 'data.runs')
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.total', 12)
            ->assertJsonPath('data.pagination.last_page', 2);

        $page2 = $this->withToken($token)->getJson('/api/v1/admin/imports?page=2');
        $page2->assertOk()
            ->assertJsonCount(2, 'data.runs')
            ->assertJsonPath('data.pagination.current_page', 2);
    }
}
