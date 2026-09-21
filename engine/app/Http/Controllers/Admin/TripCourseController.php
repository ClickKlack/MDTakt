<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TripCourseRequest;
use App\Models\ConsolidatedTrip;
use App\Models\Course;
use App\Services\CourseService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Der Kurs einer Fahrt — und damit ihrer ganzen Kette (KURSE §2 K2).
 */
final class TripCourseController extends Controller
{
    public function __construct(private readonly CourseService $courses) {}

    /** PUT /api/v1/admin/consolidated-trips/{consolidatedTrip}/course */
    public function update(TripCourseRequest $request, ConsolidatedTrip $consolidatedTrip): JsonResponse
    {
        $consolidatedTrip->loadMissing('lineVersion');
        $version = $consolidatedTrip->lineVersion;

        $kurs = $request->courseId() !== null
            ? Course::query()->findOrFail($request->courseId())
            : $this->courses->findOrCreateForChain($consolidatedTrip, (string) $request->number());

        // Ein Kurs aus einem anderen Strang waere an keinem Tag wirksam.
        if (! $this->courses->matchesStrand($kurs, $consolidatedTrip)) {
            return response()->json([
                'error' => [
                    'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                    'message' => 'Der Umlauf gehört zu einer anderen Periode oder einem anderen Fahrplantyp als die Fahrt.',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ergebnis = $this->courses->assign($consolidatedTrip, $kurs);

        return response()->json(['data' => [
            'course' => $this->courses->describe($ergebnis['course']),
            'trips_assigned' => $ergebnis['trips_assigned'],
            'trip_ids' => $ergebnis['trip_ids'],
        ]]);
    }

    /** DELETE /api/v1/admin/consolidated-trips/{consolidatedTrip}/course */
    public function destroy(ConsolidatedTrip $consolidatedTrip): JsonResponse
    {
        $ergebnis = $this->courses->detach($consolidatedTrip);

        return response()->json(['data' => [
            'course' => null,
            'trips_assigned' => $ergebnis['trips_assigned'],
            'trip_ids' => $ergebnis['trip_ids'],
        ]]);
    }
}
