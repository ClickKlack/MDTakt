<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ScheduleSource;
use App\Http\Requests\TripFilterRequest;
use App\Http\Resources\ConsolidatedTripResource;
use App\Http\Resources\TripResource;
use App\Services\ConsolidatedScheduleService;
use App\Services\TripFilterService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Öffentliche Stammdaten: Fahrten nach Datum, Linie und Haltestelle gefiltert
 * (Vorbereitung Matching).
 *
 * Aus dem **Konsolidat** (Vorgabe) beantwortet der Endpunkt jedes je beobachtete Datum; aus dem
 * **Roh-Bestand** (`?source=raw`) nur das aktuelle Feed-Fenster. Die Antwortform unterscheidet
 * sich entsprechend: Roh-Fahrten tragen `trip_id`/`service_id`, Konsolidat-Fahrten `signature`
 * und `version_no`. `meta.source` sagt, was geliefert wurde.
 */
final class TripController extends Controller
{
    public function __construct(
        private readonly TripFilterService $service,
        private readonly ConsolidatedScheduleService $consolidated,
    ) {}

    /** GET /api/v1/trips?date=&line=&stop=&source= */
    public function index(TripFilterRequest $request): AnonymousResourceCollection
    {
        $source = $request->source();

        if ($source === ScheduleSource::Raw) {
            return TripResource::collection($this->service->filter($request->filters()))
                ->additional(['meta' => ['source' => $source->value]]);
        }

        return ConsolidatedTripResource::collection($this->consolidated->trips($request->filters()))
            ->additional(['meta' => ['source' => $source->value]]);
    }
}
