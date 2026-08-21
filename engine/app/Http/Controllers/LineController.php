<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ScheduleSource;
use App\Http\Requests\LineTripsRequest;
use App\Http\Requests\ScheduleSourceRequest;
use App\Http\Resources\LineResource;
use App\Services\ConsolidatedScheduleService;
use App\Services\LineDirectoryService;
use App\Services\LineTripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Öffentliche Stammdaten: alle MVB-Linien (Tram + Bus) und ihre Fahrten.
 *
 * Beide Endpunkte antworten wahlweise aus dem **Konsolidat** (Vorgabe, dauerhaft) oder aus dem
 * **Roh-Bestand** (`?source=raw`, nur das letzte Feed-Fenster). Die Admin-Schaltzentrale nutzt
 * beides, der öffentliche Viewer ausschließlich das Konsolidat.
 */
final class LineController extends Controller
{
    public function __construct(
        private readonly LineTripService $lineTrips,
        private readonly LineDirectoryService $lines,
        private readonly ConsolidatedScheduleService $consolidated,
    ) {}

    /** GET /api/v1/lines?source= — eine Zeile je Linienbezeichnung, nicht je GTFS-Route */
    public function index(ScheduleSourceRequest $request): AnonymousResourceCollection
    {
        $source = $request->source();

        $lines = $source === ScheduleSource::Raw
            ? $this->lines->allLines()
            : $this->consolidated->lines();

        return LineResource::collection($lines)->additional(['meta' => ['source' => $source->value]]);
    }

    /** GET /api/v1/lines/{line}/trips?day_type=&source= — Fahrten gruppiert nach Start → Ziel */
    public function trips(LineTripsRequest $request, string $line): JsonResponse
    {
        $source = $request->source();

        $data = $source === ScheduleSource::Raw
            ? $this->lineTrips->groupedByStartEnd($line, $request->dayType())
            : $this->consolidated->groupedByStartEnd($line, $request->dayType());

        return response()->json([
            'data' => $data,
            'meta' => ['source' => $source->value],
        ]);
    }
}
