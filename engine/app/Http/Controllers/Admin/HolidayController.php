<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\HolidayService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only-Ansicht der berechneten Feiertage (Sachsen-Anhalt) zur Kontrolle.
 * Werden nicht persistiert — der HolidayService berechnet sie je Jahr.
 */
final class HolidayController extends Controller
{
    public function __construct(private readonly HolidayService $holidays) {}

    /** GET /api/v1/admin/holidays?year= */
    public function index(Request $request): JsonResponse
    {
        $year = $request->integer('year', (int) CarbonImmutable::now()->year);

        $days = [];
        foreach ($this->holidays->forYear($year) as $date => $name) {
            $days[] = ['date' => $date, 'name' => $name];
        }

        return response()->json(['data' => ['year' => $year, 'holidays' => $days]]);
    }
}
