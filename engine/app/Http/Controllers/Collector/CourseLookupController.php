<?php

declare(strict_types=1);

namespace App\Http\Controllers\Collector;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseLookupRequest;
use App\Http\Resources\CourseLookupResource;
use App\Services\DepartureCourseLookupService;
use Illuminate\Http\JsonResponse;

/**
 * Kursauskunft für MDKursTracker (Fluss 2). Dünn — die Logik liegt im DepartureCourseLookupService.
 *
 * Die Antwort darf der Tracker eine Stunde cachen: Ein gerade angenommener Kurs soll noch am selben
 * Tag ankommen, eine Abfahrtstafel aber nicht bei jedem Aufruf neu fragen.
 */
final class CourseLookupController extends Controller
{
    private const CACHE_CONTROL = 'private, max-age=3600';

    public function __construct(private readonly DepartureCourseLookupService $service) {}

    /** GET /api/v1/collector/course-lookup */
    public function show(CourseLookupRequest $request): JsonResponse
    {
        return CourseLookupResource::make($this->service->lookup($request->validated()))
            ->response()
            ->header('Cache-Control', self::CACHE_CONTROL);
    }

    /** POST /api/v1/collector/course-lookup — bis zu 100 Abfahrten einer Tafel auf einmal */
    public function batch(CourseLookupRequest $request): JsonResponse
    {
        $ergebnisse = array_map(
            fn (array $abfahrt): array => ['ref' => $abfahrt['ref'] ?? null] + $this->service->lookup($abfahrt),
            $request->validated()['departures'],
        );

        return CourseLookupResource::collection($ergebnisse)
            ->response()
            ->header('Cache-Control', self::CACHE_CONTROL);
    }
}
