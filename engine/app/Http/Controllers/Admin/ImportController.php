<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GtfsImportStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Import-Auditing fürs Admin-Frontend (Sanctum-geschützt).
 * Nutzt denselben GtfsImportStatusService wie die Collector-Token-Variante.
 */
final class ImportController extends Controller
{
    public function __construct(private readonly GtfsImportStatusService $status) {}

    /** GET /api/v1/admin/imports */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->status->status($request->integer('page', 1), $request->integer('per_page', 10)),
        ]);
    }
}
