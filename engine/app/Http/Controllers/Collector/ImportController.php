<?php

declare(strict_types=1);

namespace App\Http\Controllers\Collector;

use App\Enums\GtfsImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportStartRequest;
use App\Http\Requests\StopTimesChunkRequest;
use App\Http\Resources\GtfsImportResource;
use App\Models\GtfsImportRun;
use App\Services\GtfsImportService;
use App\Services\GtfsImportStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GTFS-Import als gechunkter Lebenszyklus (Collector → Engine):
 *   POST imports                  → Lauf starten (Basistabellen)
 *   POST imports/{run}/stop-times → stop_times-Chunk anhängen (mehrfach)
 *   POST imports/{run}/finish     → Lauf abschließen
 *   GET  imports                  → Historie & Datenstand
 *
 * Dünn — die Logik liegt im GtfsImportService.
 */
final class ImportController extends Controller
{
    public function __construct(
        private readonly GtfsImportService $service,
        private readonly GtfsImportStatusService $status,
    ) {}

    /** GET /api/v1/collector/imports */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->status->status($request->integer('page', 1), $request->integer('per_page', 10)),
        ]);
    }

    /** POST /api/v1/collector/imports */
    public function start(ImportStartRequest $request): JsonResponse
    {
        /** @var array{routes: array, stops: array, trips: array, calendar?: array, calendar_dates: array, feed_info?: array} $data */
        $data = $request->only(['routes', 'stops', 'trips', 'calendar', 'calendar_dates', 'feed_info']);

        $run = $this->service->startRun($data);

        return GtfsImportResource::make($run)->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /** POST /api/v1/collector/imports/{run}/stop-times */
    public function stopTimes(StopTimesChunkRequest $request, GtfsImportRun $run): JsonResponse
    {
        if ($guard = $this->ensureRunning($run)) {
            return $guard;
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $request->validated()['stop_times'];
        $run = $this->service->appendStopTimes($run, $rows);

        return GtfsImportResource::make($run)->response();
    }

    /** POST /api/v1/collector/imports/{run}/finish */
    public function finish(GtfsImportRun $run): JsonResponse
    {
        if ($guard = $this->ensureRunning($run)) {
            return $guard;
        }

        $run = $this->service->finishRun($run);

        return GtfsImportResource::make($run)->response();
    }

    /**
     * Stellt sicher, dass der Lauf noch offen ist — sonst 409 im Fehler-Envelope.
     */
    private function ensureRunning(GtfsImportRun $run): ?JsonResponse
    {
        if ($run->status === GtfsImportStatus::Running) {
            return null;
        }

        return response()->json([
            'error' => [
                'code' => JsonResponse::HTTP_CONFLICT,
                'message' => "Import run {$run->id} is already {$run->status->value}.",
            ],
        ], JsonResponse::HTTP_CONFLICT);
    }
}
