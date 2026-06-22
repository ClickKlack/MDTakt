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
 * Der erwartete Token liegt in `config('services.collector.token')` (env COLLECTOR_API_TOKEN).
 * Antwortet im einheitlichen Fehlerformat { "error": { "code", "message" } }.
 */
final class CollectorTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.collector.token');
        $provided = $request->bearerToken();

        // Ohne konfigurierten Token ist der Endpunkt grundsätzlich gesperrt — Fail-Closed.
        if (empty($expected)) {
            Log::error('Collector endpoint called but COLLECTOR_API_TOKEN is not configured', [
                'path' => $request->path(),
            ]);

            return $this->unauthorized();
        }

        // hash_equals gegen Timing-Angriffe; kein Token-Inhalt ins Log.
        if ($provided === null || ! hash_equals((string) $expected, $provided)) {
            Log::warning('Collector authentication failed', [
                'path' => $request->path(),
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
