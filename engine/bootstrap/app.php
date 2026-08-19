<?php

use App\Http\Middleware\CollectorTokenMiddleware;
use App\Http\Middleware\DecompressRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'collector.token' => CollectorTokenMiddleware::class,
            'decompress' => DecompressRequest::class,
        ]);

        // Die Engine ist ein reiner API-Server ohne Login-Web-Route. Laravels Default
        // (`redirectGuestsTo(fn () => route('login'))`) wird von der Authenticate-Middleware
        // schon ausgewertet, wenn ein Request kein JSON erwartet — das liefe hier in eine
        // RouteNotFoundException (500) statt in den 401-Envelope. Kein Redirect-Ziel = kein Redirect.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Nicht authentifiziert → einheitlicher Fehler-Envelope statt Laravel-Default.
        $exceptions->render(function (AuthenticationException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => JsonResponse::HTTP_UNAUTHORIZED,
                    'message' => 'Unauthenticated.',
                ],
            ], JsonResponse::HTTP_UNAUTHORIZED);
        });

        // Rate-Limit überschritten → gleicher Envelope wie alle anderen API-Fehler.
        // Laravel würde sonst { "message": "Too Many Attempts." } liefern.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => JsonResponse::HTTP_TOO_MANY_REQUESTS,
                    'message' => 'Too many requests.',
                ],
            ], JsonResponse::HTTP_TOO_MANY_REQUESTS, $e->getHeaders());
        });
    })->create();
