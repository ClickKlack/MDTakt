<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LineColorUpdateRequest;
use App\Models\LineColor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Pflege der Linienfarben (Sanctum-geschützt). Geschlüsselt auf route_short_name,
 * damit auch neue/unbekannte Linien eine Farbe bekommen können.
 */
final class LineColorController extends Controller
{
    /** PUT /api/v1/admin/line-colors/{line} */
    public function update(LineColorUpdateRequest $request, string $line): JsonResponse
    {
        /** @var array{color: string} $data */
        $data = $request->validated();

        $lineColor = LineColor::query()->updateOrCreate(
            ['route_short_name' => $line],
            ['color' => $data['color']],
        );

        Log::info('Line color saved', ['line' => $line, 'color' => $data['color']]);

        return response()->json([
            'data' => ['route_short_name' => $lineColor->route_short_name, 'color' => $lineColor->color],
        ]);
    }

    /** DELETE /api/v1/admin/line-colors/{line} — Farbe zurücksetzen */
    public function destroy(string $line): JsonResponse
    {
        LineColor::query()->where('route_short_name', $line)->delete();

        Log::info('Line color reset', ['line' => $line]);

        return response()->json(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
