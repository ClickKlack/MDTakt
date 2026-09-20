<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ScheduleSource;
use App\Http\Requests\ScheduleSourceRequest;
use App\Http\Resources\ConsolidatedStopResource;
use App\Http\Resources\StopResource;
use App\Models\Stop;
use App\Services\ConsolidatedStopDirectoryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Öffentliche Stammdaten: alle MVB-Haltestellen.
 *
 * Die Quelle bestimmt die **Identität**, nicht nur die Reichweite: Im Roh-Bestand ist eine
 * Zeile eine GTFS-`stop_id` (mehrere pro Name, pro Build neu vergeben), im Konsolidat ein
 * physischer Punkt mit ganzzahliger, dauerhafter ID. Die Umlauf-Pflege arbeitet auf letzterer.
 */
final class StopController extends Controller
{
    public function __construct(private readonly ConsolidatedStopDirectoryService $consolidated) {}

    /** GET /api/v1/stops?source= */
    public function index(ScheduleSourceRequest $request): AnonymousResourceCollection
    {
        $source = $request->source();

        if ($source === ScheduleSource::Raw) {
            return StopResource::collection(Stop::query()->orderBy('stop_name')->get())
                ->additional(['meta' => ['source' => $source->value]]);
        }

        return ConsolidatedStopResource::collection($this->consolidated->allStops())
            ->additional(['meta' => ['source' => $source->value]]);
    }
}
