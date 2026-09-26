<?php

declare(strict_types=1);

namespace App\Http\Controllers\Collector;

use App\Http\Controllers\Controller;
use App\Http\Requests\SightingIngestRequest;
use App\Http\Resources\SightingIngestResource;
use App\Services\SightingIngestService;

/**
 * Sichtungs-Eingang aus MDKursTracker. Dünn — die Logik liegt im SightingIngestService.
 */
final class SightingController extends Controller
{
    public function __construct(private readonly SightingIngestService $service) {}

    /** POST /api/v1/collector/sightings */
    public function store(SightingIngestRequest $request): SightingIngestResource
    {
        $daten = $request->validated();

        return SightingIngestResource::make($this->service->ingest($daten['trips'], $daten['sightings']));
    }
}
