<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\PeriodOfferStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PeriodChangeAcceptRequest;
use App\Http\Resources\PeriodChangeOfferResource;
use App\Http\Resources\SchedulePeriodResource;
use App\Models\PeriodChangeOffer;
use App\Services\PeriodChangeOfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Periodenwechsel-Vorschläge annehmen oder ablehnen (FAHRPLANPERIODEN §4.3).
 */
final class PeriodChangeOfferController extends Controller
{
    public function __construct(private readonly PeriodChangeOfferService $offers) {}

    /** GET /api/v1/admin/period-change-offers */
    public function index(): AnonymousResourceCollection
    {
        return PeriodChangeOfferResource::collection($this->offers->open());
    }

    /** POST /api/v1/admin/period-change-offers/{offer}/accept */
    public function accept(PeriodChangeAcceptRequest $request, PeriodChangeOffer $offer): JsonResponse
    {
        if ($offer->status !== PeriodOfferStatus::Open) {
            return $this->bereitsBeschieden($offer);
        }

        $periode = $this->offers->accept($offer, $request->label());

        return SchedulePeriodResource::make($periode->loadCount('lineVersions'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** POST /api/v1/admin/period-change-offers/{offer}/decline */
    public function decline(PeriodChangeOffer $offer): JsonResponse|PeriodChangeOfferResource
    {
        if ($offer->status !== PeriodOfferStatus::Open) {
            return $this->bereitsBeschieden($offer);
        }

        $this->offers->decline($offer);

        return PeriodChangeOfferResource::make($offer->refresh());
    }

    private function bereitsBeschieden(PeriodChangeOffer $offer): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => Response::HTTP_CONFLICT,
                'message' => 'Dieser Vorschlag wurde bereits '.$offer->status->label().'.',
            ],
        ], Response::HTTP_CONFLICT);
    }
}
