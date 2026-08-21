<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SchedulePeriodRequest;
use App\Http\Resources\SchedulePeriodResource;
use App\Models\SchedulePeriod;
use App\Services\SchedulePeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pflege der netzweiten Fahrplanperioden (Sanctum-geschützt, FAHRPLANPERIODEN §4.1).
 */
final class SchedulePeriodController extends Controller
{
    public function __construct(private readonly SchedulePeriodService $periods) {}

    /** GET /api/v1/admin/schedule-periods */
    public function index(): AnonymousResourceCollection
    {
        return SchedulePeriodResource::collection($this->periods->list());
    }

    /** POST /api/v1/admin/schedule-periods */
    public function store(SchedulePeriodRequest $request): JsonResponse
    {
        $periode = $this->periods->create($request->label(), $request->validFrom());

        return SchedulePeriodResource::make($periode)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** PUT /api/v1/admin/schedule-periods/{period} */
    public function update(SchedulePeriodRequest $request, SchedulePeriod $period): SchedulePeriodResource
    {
        $periode = $this->periods->update($period, $request->label(), $request->validFrom());

        return SchedulePeriodResource::make($periode->loadCount('lineVersions'));
    }

    /** DELETE /api/v1/admin/schedule-periods/{period} */
    public function destroy(SchedulePeriod $period): JsonResponse
    {
        // Der FK löscht Versionen und Intervalle kaskadierend mit. Beobachtete Fahrplan-Historie
        // lässt sich aber nicht wiederbeschaffen — deshalb hier abweisen statt aufräumen.
        if ($period->lineVersions()->exists()) {
            Log::warning('Schedule period deletion refused, line versions attached', [
                'period_id' => $period->id,
            ]);

            return response()->json([
                'error' => [
                    'code' => Response::HTTP_CONFLICT,
                    'message' => 'An dieser Periode hängen Linien-Versionen. Sie kann nicht gelöscht werden.',
                ],
            ], Response::HTTP_CONFLICT);
        }

        $this->periods->delete($period);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
