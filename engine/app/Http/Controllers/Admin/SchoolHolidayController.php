<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SchoolHolidayRequest;
use App\Http\Resources\SchoolHolidayResource;
use App\Models\SchoolHoliday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

/**
 * Pflege der Schulferien-Zeiträume (Sanctum-geschützt). Grundlage der
 * Fahrplantyp-Klassifikation („Mo-Fr Ferien").
 */
final class SchoolHolidayController extends Controller
{
    /** GET /api/v1/admin/school-holidays */
    public function index(): AnonymousResourceCollection
    {
        return SchoolHolidayResource::collection(
            SchoolHoliday::query()->orderBy('start_date')->get(),
        );
    }

    /** POST /api/v1/admin/school-holidays */
    public function store(SchoolHolidayRequest $request): JsonResponse
    {
        $holiday = SchoolHoliday::query()->create($request->validated());

        Log::info('School holiday created', ['id' => $holiday->id, 'name' => $holiday->name]);

        return SchoolHolidayResource::make($holiday)->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /** PUT /api/v1/admin/school-holidays/{schoolHoliday} */
    public function update(SchoolHolidayRequest $request, SchoolHoliday $schoolHoliday): SchoolHolidayResource
    {
        $schoolHoliday->update($request->validated());

        Log::info('School holiday updated', ['id' => $schoolHoliday->id]);

        return SchoolHolidayResource::make($schoolHoliday);
    }

    /** DELETE /api/v1/admin/school-holidays/{schoolHoliday} */
    public function destroy(SchoolHoliday $schoolHoliday): JsonResponse
    {
        $schoolHoliday->delete();

        Log::info('School holiday deleted', ['id' => $schoolHoliday->id]);

        return response()->json(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
