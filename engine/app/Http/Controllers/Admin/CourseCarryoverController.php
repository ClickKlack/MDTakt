<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseCarryoverRequest;
use App\Models\LineVersion;
use App\Services\CourseCarryoverService;
use Illuminate\Http\JsonResponse;

/**
 * Übernahme von Kursen und Anschlüssen beim Versionswechsel (KURSE §2 K4).
 *
 * Bewusst zwei Endpunkte statt eines Schalters: Die Vorschau ist garantiert folgenlos, und erst
 * das POST ändert etwas. Wer die Vorschau aufruft, soll sich darauf verlassen koennen.
 */
final class CourseCarryoverController extends Controller
{
    public function __construct(private readonly CourseCarryoverService $carryover) {}

    /** GET /api/v1/admin/line-versions/{lineVersion}/course-carryover?from= */
    public function show(CourseCarryoverRequest $request, LineVersion $lineVersion): JsonResponse
    {
        return response()->json([
            'data' => $this->carryover->preview($request->fromVersion(), $lineVersion),
        ]);
    }

    /** POST /api/v1/admin/line-versions/{lineVersion}/course-carryover */
    public function store(CourseCarryoverRequest $request, LineVersion $lineVersion): JsonResponse
    {
        return response()->json([
            'data' => $this->carryover->apply($request->fromVersion(), $lineVersion),
        ]);
    }
}
