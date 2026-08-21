<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CoverageService;
use Illuminate\Http\JsonResponse;

/**
 * Abdeckung des Konsolidats: welche Zeiträume je Linie und Fahrplantyp abrufbar sind
 * und wo Lücken klaffen (FAHRPLANPERIODEN Phase C).
 */
final class CoverageController extends Controller
{
    public function __construct(private readonly CoverageService $coverage) {}

    /** GET /api/v1/admin/coverage */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->coverage->overview()]);
    }
}
