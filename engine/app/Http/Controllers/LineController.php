<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\RouteResource;
use App\Models\Route;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Öffentliche Stammdaten: alle MVB-Linien (Tram + Bus).
 */
final class LineController extends Controller
{
    /** GET /api/v1/lines */
    public function index(): AnonymousResourceCollection
    {
        $routes = Route::query()
            ->orderBy('route_type')
            ->orderBy('route_short_name')
            ->get();

        return RouteResource::collection($routes);
    }
}
