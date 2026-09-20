<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseFilterRequest;
use App\Http\Requests\CourseRequest;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Services\CourseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Umläufe: die Kursnummern eines Strangs (Periode + Fahrplantyp).
 */
final class CourseController extends Controller
{
    public function __construct(private readonly CourseService $courses) {}

    /** GET /api/v1/admin/courses?period=&day_type=&line= */
    public function index(CourseFilterRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->courses->overview($request->period(), $request->dayType(), $request->line()),
        ]);
    }

    /** POST /api/v1/admin/courses */
    public function store(CourseRequest $request): JsonResponse
    {
        $kurs = Course::query()->create($request->validated());

        Log::info('Course created', ['course_id' => $kurs->id, 'number' => $kurs->number]);

        return CourseResource::make($this->courses->describe($kurs))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** PUT /api/v1/admin/courses/{course} */
    public function update(CourseRequest $request, Course $course): CourseResource
    {
        $course->update($request->validated());

        Log::info('Course updated', ['course_id' => $course->id, 'number' => $course->number]);

        return CourseResource::make($this->courses->describe($course->refresh()));
    }

    /** DELETE /api/v1/admin/courses/{course} */
    public function destroy(Course $course): JsonResponse
    {
        // cascadeOnDelete raeumt die Zugehoerigkeiten ab; Fahrten und Verkettung bleiben.
        $course->delete();

        Log::info('Course deleted', ['course_id' => $course->id]);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
