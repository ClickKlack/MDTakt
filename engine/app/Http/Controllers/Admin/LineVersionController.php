<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LineVersionFilterRequest;
use App\Services\LineVersionOverviewService;
use Illuminate\Http\JsonResponse;

/**
 * Fahrplan-Änderungshistorie der laufenden Periode (Admin-Schaltzentrale, I-13).
 */
final class LineVersionController extends Controller
{
    public function __construct(private readonly LineVersionOverviewService $overview) {}

    /** GET /api/v1/admin/line-versions?line=&day_type= */
    public function index(LineVersionFilterRequest $request): JsonResponse
    {
        $data = $this->overview->overview($request->line(), $request->dayType());

        return response()->json([
            'data' => [
                'period' => $data['period'] === null ? null : [
                    'id' => $data['period']->id,
                    'label' => $data['period']->label,
                    'valid_from' => $data['period']->valid_from->toDateString(),
                    'valid_to' => $data['period']->valid_to?->toDateString(),
                    'status' => $data['period']->status->value,
                    'created_via' => $data['period']->created_via->value,
                ],
                'coverage' => $data['coverage'],
                'lines' => $data['lines'],
            ],
        ]);
    }
}
