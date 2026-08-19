<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CorsTest extends TestCase
{
    use RefreshDatabase;

    private const ERLAUBT = 'https://admin.example.test';

    private const ZWEITER = 'https://viewer.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        // Zwei Origins wie in Produktion (Admin + Viewer). Wichtig fuer die Aussagekraft:
        // Bei genau EINEM erlaubten Origin setzt php-cors den Header unbedingt auf diesen
        // Wert — dann kann ein fremder Origin gar nicht geprueft werden.
        config(['cors.allowed_origins' => [self::ERLAUBT, self::ZWEITER]]);
    }

    public function test_allowed_origin_receives_cors_header(): void
    {
        $this->getJson('/api/v1/lines', ['Origin' => self::ERLAUBT])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::ERLAUBT);
    }

    public function test_foreign_origin_receives_no_cors_header(): void
    {
        // Ohne den Header verweigert der Browser dem fremden Origin den Zugriff aufs Ergebnis.
        $response = $this->getJson('/api/v1/lines', ['Origin' => 'https://fremde-seite.test']);

        $response->assertOk();
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));

        // Vary: Origin, damit Caches die Antwort nicht origin-uebergreifend wiederverwenden.
        $this->assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
    }

    public function test_second_allowed_origin_is_accepted_too(): void
    {
        $this->getJson('/api/v1/lines', ['Origin' => self::ZWEITER])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::ZWEITER);
    }

    public function test_preflight_is_answered_for_allowed_origin(): void
    {
        $this->call('OPTIONS', '/api/v1/admin/imports', [], [], [], [
            'HTTP_ORIGIN' => self::ERLAUBT,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::ERLAUBT);
    }

    public function test_rate_limit_headers_are_exposed(): void
    {
        // Ohne exposed_headers koennte das Frontend sie nicht auslesen.
        $this->getJson('/api/v1/lines', ['Origin' => self::ERLAUBT])
            ->assertHeader('Access-Control-Expose-Headers');
    }
}
