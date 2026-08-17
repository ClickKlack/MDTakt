<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LineTripsRequest;
use App\Http\Resources\LineResource;
use App\Services\LineDirectoryService;
use App\Services\LineTripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Öffentliche Stammdaten: alle MVB-Linien (Tram + Bus) und ihre Fahrten.
 */
final class LineController extends Controller
{
    public function __construct(
        private readonly LineTripService $lineTrips,
        private readonly LineDirectoryService $lines,
    ) {}

    /** GET /api/v1/lines — eine Zeile je Linienbezeichnung, nicht je GTFS-Route */
    public function index(): AnonymousResourceCollection
    {
        return LineResource::collection($this->lines->allLines());
    }

    /** GET /api/v1/lines/{line}/trips?day_type= — Fahrten gruppiert nach Start → Ziel */
    public function trips(LineTripsRequest $request, string $line): JsonResponse
    {
        return response()->json([
            'data' => $this->lineTrips->groupedByStartEnd($line, $request->dayType()),
        ]);
    }
}
