<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SightingDecisionRequest;
use App\Http\Requests\SightingFilterRequest;
use App\Http\Resources\SightingCountsResource;
use App\Http\Resources\SightingDecisionResource;
use App\Http\Resources\SightingResource;
use App\Services\SightingReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prüfliste der Sichtungen aus MDKursTracker. Dünn — die Logik liegt im SightingReviewService.
 */
final class SightingController extends Controller
{
    public function __construct(private readonly SightingReviewService $review) {}

    /** GET /api/v1/admin/sightings */
    public function index(SightingFilterRequest $request): AnonymousResourceCollection
    {
        $filter = $request->validated();
        $ergebnis = $this->review->list(
            $filter,
            (int) ($filter['page'] ?? 1),
            (int) ($filter['per_page'] ?? 50),
        );

        return SightingResource::collection($ergebnis['items'])->additional(['meta' => $ergebnis['meta']]);
    }

    /** GET /api/v1/admin/sightings/counts */
    public function counts(): SightingCountsResource
    {
        return SightingCountsResource::make($this->review->counts());
    }

    /** POST /api/v1/admin/sightings/accept */
    public function accept(SightingDecisionRequest $request): SightingDecisionResource|JsonResponse
    {
        try {
            return SightingDecisionResource::make($this->review->accept($request->ids()));
        } catch (InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }
    }

    /** POST /api/v1/admin/sightings/reject */
    public function reject(SightingDecisionRequest $request): SightingDecisionResource|JsonResponse
    {
        try {
            return SightingDecisionResource::make(
                $this->review->reject($request->ids(), $request->validated()['note'] ?? null)
            );
        } catch (InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }
    }

    private function unprocessable(string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => $message],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
