<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimetableResource;
use App\Models\LineVersion;
use App\Services\TimetableService;

/**
 * Fahrplan einer Linien-Version als Matrix (Halte als Zeilen, Fahrten als Spalten).
 */
final class TimetableController extends Controller
{
    public function __construct(private readonly TimetableService $timetable) {}

    /** GET /api/v1/admin/line-versions/{lineVersion}/timetable */
    public function show(LineVersion $lineVersion): TimetableResource
    {
        return TimetableResource::make($this->timetable->forVersion($lineVersion));
    }
}
