<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\RouteResource;
use App\Models\Route;
use App\Services\LineTripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Öffentliche Stammdaten: alle MVB-Linien (Tram + Bus) und ihre Fahrten.
 */
final class LineController extends Controller
{
    public function __construct(private readonly LineTripService $lineTrips) {}

    /** GET /api/v1/lines */
    public function index(): AnonymousResourceCollection
    {
        $routes = Route::query()
            ->with('lineColor')
            ->orderBy('route_type')
            ->orderBy('route_short_name')
            ->get();

        return RouteResource::collection($routes);
    }

    /** GET /api/v1/lines/{line}/trips — Fahrten gruppiert nach Start → Ziel */
    public function trips(string $line): JsonResponse
    {
        return response()->json(['data' => $this->lineTrips->groupedByStartEnd($line)]);
    }
}
