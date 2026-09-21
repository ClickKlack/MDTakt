<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseFilterRequest;
use App\Services\CourseOverviewService;
use Illuminate\Http\JsonResponse;

/**
 * Die Umläufe einer Linie im Ganzen — Ketten, Risse und die Fahrten ohne Kurs.
 */
final class LineCourseController extends Controller
{
    public function __construct(private readonly CourseOverviewService $overview) {}

    /** GET /api/v1/admin/lines/{line}/courses?period=&day_type= */
    public function index(CourseFilterRequest $request, string $line): JsonResponse
    {
        return response()->json([
            'data' => $this->overview->forLine($line, $request->period(), $request->dayType()),
        ]);
    }
}
