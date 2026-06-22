<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\StopResource;
use App\Models\Stop;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Öffentliche Stammdaten: alle MVB-Haltestellen.
 */
final class StopController extends Controller
{
    /** GET /api/v1/stops */
    public function index(): AnonymousResourceCollection
    {
        $stops = Stop::query()->orderBy('stop_name')->get();

        return StopResource::collection($stops);
    }
}
