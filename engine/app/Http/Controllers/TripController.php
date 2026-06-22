<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TripFilterRequest;
use App\Http\Resources\TripResource;
use App\Services\TripFilterService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Öffentliche Stammdaten: GTFS-Trips nach Datum, Linie und Haltestelle gefiltert
 * (Vorbereitung Matching). Logik liegt im TripFilterService.
 */
final class TripController extends Controller
{
    public function __construct(private readonly TripFilterService $service) {}

    /** GET /api/v1/trips?date=&line=&stop= */
    public function index(TripFilterRequest $request): AnonymousResourceCollection
    {
        $trips = $this->service->filter($request->filters());

        return TripResource::collection($trips);
    }
}
