<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entpackt gzip-komprimierte Request-Bodies (Header `Content-Encoding: gzip`).
 *
 * Der Collector komprimiert große Import-Payloads, damit sie unter PHPs
 * `post_max_size` bleiben. Ohne den Header passiert nichts — unkomprimierte
 * Requests (Tests, Bruno) laufen unverändert durch.
 */
final class DecompressRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (strtolower((string) $request->headers->get('Content-Encoding')) !== 'gzip') {
            return $next($request);
        }

        $raw = $request->getContent();
        if ($raw === '') {
            return $next($request);
        }

        $decoded = @gzdecode($raw);
        if ($decoded === false) {
            return response()->json([
                'error' => [
                    'code' => Response::HTTP_BAD_REQUEST,
                    'message' => 'Invalid gzip request body.',
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        // Header bereinigen und entpackte JSON-Daten als Eingabequelle setzen,
        // damit FormRequests/Validierung die echten Daten sehen.
        $request->headers->remove('Content-Encoding');
        $request->headers->set('Content-Length', (string) strlen($decoded));

        $json = json_decode($decoded, true);
        if (is_array($json)) {
            $request->setJson(new InputBag($json));
        }

        return $next($request);
    }
}
