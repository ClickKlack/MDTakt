<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LineVersionDiffRequest;
use App\Http\Resources\LineVersionDiffResource;
use App\Services\LineVersionDiffService;

/**
 * Unterschied zweier Fahrplan-Versionen auf Fahrt-Ebene (FAHRPLANPERIODEN §5.4).
 */
final class LineVersionDiffController extends Controller
{
    public function __construct(private readonly LineVersionDiffService $diff) {}

    /** GET /api/v1/admin/line-version-diff?from=&to=&include=stops */
    public function show(LineVersionDiffRequest $request): LineVersionDiffResource
    {
        return LineVersionDiffResource::make($this->diff->diff(
            $request->fromVersion(),
            $request->toVersion(),
            $request->withStops(),
        ));
    }
}
