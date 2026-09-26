<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Schützt die Collector-Endpunkte mit einem statischen Bearer-Token.
 *
 * Der erwartete Token liegt in `config('services.{quelle}.token')`. Ohne Parameter ist die Quelle
 * der NAS-Collector (env COLLECTOR_API_TOKEN); der Sichtungs-Eingang nutzt
 * `collector.token:mdkurstracker` (env MDKURSTRACKER_API_TOKEN). Getrennte Tokens, damit der
 * Tracker keine GTFS-Importe auslösen kann und sich jeder Zugang einzeln zurückziehen lässt.
 *
 * Antwortet im einheitlichen Fehlerformat { "error": { "code", "message" } }.
 */
final class CollectorTokenMiddleware
{
    public function handle(Request $request, Closure $next, string $source = 'collector'): Response
    {
        $expected = config("services.{$source}.token");
        $provided = $request->bearerToken();

        // Ohne konfigurierten Token ist der Endpunkt grundsätzlich gesperrt — Fail-Closed.
        if (empty($expected)) {
            Log::error('Collector endpoint called but its API token is not configured', [
                'path' => $request->path(),
                'source' => $source,
            ]);

            return $this->unauthorized();
        }

        // hash_equals gegen Timing-Angriffe; kein Token-Inhalt ins Log.
        if ($provided === null || ! hash_equals((string) $expected, $provided)) {
            Log::warning('Collector authentication failed', [
                'path' => $request->path(),
                'source' => $source,
                'ip' => $request->ip(),
            ]);

            return $this->unauthorized();
        }

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'error' => [
                'code' => Response::HTTP_UNAUTHORIZED,
                'message' => 'Invalid or missing collector token.',
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
