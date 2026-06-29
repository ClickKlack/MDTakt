<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LineColor;
use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LineColorsTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    public function test_setting_color_requires_auth(): void
    {
        $this->putJson('/api/v1/admin/line-colors/1', ['color' => '#c9346c'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 401);
    }

    public function test_admin_can_set_and_reset_line_color(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)->putJson('/api/v1/admin/line-colors/1', ['color' => '#c9346c'])
            ->assertOk()
            ->assertJsonPath('data.color', '#c9346c');

        $this->assertDatabaseHas('line_colors', ['route_short_name' => '1', 'color' => '#c9346c']);

        // Ändern (Upsert)
        $this->withToken($token)->putJson('/api/v1/admin/line-colors/1', ['color' => '#000000'])
            ->assertOk()
            ->assertJsonPath('data.color', '#000000');
        $this->assertDatabaseCount('line_colors', 1);

        // Zurücksetzen
        $this->withToken($token)->deleteJson('/api/v1/admin/line-colors/1')->assertNoContent();
        $this->assertDatabaseMissing('line_colors', ['route_short_name' => '1']);
    }

    public function test_invalid_color_returns_422(): void
    {
        $this->withToken($this->adminToken())
            ->putJson('/api/v1/admin/line-colors/1', ['color' => 'rot'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_lines_endpoint_exposes_color(): void
    {
        Route::factory()->tram()->create(['route_id' => 'R1', 'route_short_name' => '1']);
        LineColor::query()->create(['route_short_name' => '1', 'color' => '#c9346c']);

        $this->getJson('/api/v1/lines')
            ->assertOk()
            ->assertJsonPath('data.0.color', '#c9346c');
    }
}
