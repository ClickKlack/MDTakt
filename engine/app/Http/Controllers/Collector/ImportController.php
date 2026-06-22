<?php

declare(strict_types=1);

namespace App\Http\Controllers\Collector;

use App\Enums\GtfsImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportStartRequest;
use App\Http\Requests\StopTimesChunkRequest;
use App\Http\Resources\GtfsImportResource;
use App\Http\Resources\GtfsImportRunResource;
use App\Models\GtfsImportRun;
use App\Services\GtfsImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    public function __construct(private readonly GtfsImportService $service) {}

    /** GET /api/v1/collector/imports */
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', 20), 100));

        $runs = GtfsImportRun::query()->orderByDesc('id')->limit($limit)->get();

        $lastSuccess = GtfsImportRun::query()
            ->where('status', GtfsImportStatus::Success)
            ->orderByDesc('finished_at')
            ->first();

        return response()->json([
            'data' => [
                'current' => [
                    'last_success_at' => $lastSuccess?->finished_at?->toIso8601String(),
                    'feed_version' => $lastSuccess?->feed_version,
                    'tables' => [
                        'routes' => DB::table('routes')->count(),
                        'stops' => DB::table('stops')->count(),
                        'trips' => DB::table('trips')->count(),
                        'stop_times' => DB::table('stop_times')->count(),
                        'calendar' => DB::table('calendar')->count(),
                        'calendar_dates' => DB::table('calendar_dates')->count(),
                    ],
                ],
                'runs' => GtfsImportRunResource::collection($runs)->resolve(),
            ],
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
