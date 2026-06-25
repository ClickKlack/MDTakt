<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('s3cret-pass'),
        ]);
    }

    public function test_login_with_valid_credentials_returns_token(): void
    {
        $this->admin();

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@example.com',
            'password' => 's3cret-pass',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'admin@example.com')
            ->assertJsonStructure(['data' => ['id', 'name', 'email'], 'token', 'token_type']);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_with_wrong_password_returns_401_envelope(): void
    {
        $this->admin();

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(401)->assertJsonPath('error.code', 401);
    }

    public function test_login_validation_error_returns_422(): void
    {
        $response = $this->postJson('/api/v1/admin/login', ['email' => 'not-an-email']);

        $response->assertStatus(422)->assertJsonPath('error.code', 422);
    }

    public function test_protected_endpoint_without_token_returns_401_envelope(): void
    {
        $response = $this->getJson('/api/v1/admin/me');

        $response->assertStatus(401)->assertJsonPath('error.code', 401);
    }

    public function test_protected_endpoint_with_token_returns_admin(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/admin/me');

        $response->assertOk()->assertJsonPath('data.email', 'admin@example.com');
    }

    public function test_logout_revokes_current_token(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('test')->plainTextToken;

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($token)->postJson('/api/v1/admin/logout')->assertNoContent();

        // Token wurde widerrufen (Zeile entfernt) → bei einem echten Folge-Request 401.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
