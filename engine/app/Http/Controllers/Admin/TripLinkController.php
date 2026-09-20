<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\TripLinkKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\TripLinkRequest;
use App\Http\Resources\TripLinkResource;
use App\Models\TripLink;
use App\Services\TripLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Umlauf-Entscheidungen: Anschluss zweier Fahrten, oder die bewusste Aussage, dass eine Kette
 * hier beginnt bzw. endet (Betriebsfahrt).
 */
final class TripLinkController extends Controller
{
    public function __construct(private readonly TripLinkService $links) {}

    /** POST /api/v1/admin/trip-links */
    public function store(TripLinkRequest $request): JsonResponse
    {
        $kind = $request->kind();
        $von = $request->fromTrip();
        $nach = $request->toTrip();

        // Belegt heißt belegt: Die Unique-Constraints würden das ohnehin abweisen, aber mit
        // einem Datenbankfehler statt mit einer Meldung, die sagt, was schon entschieden ist.
        $belegt = $this->existingDecision($von?->id, $nach?->id);

        if ($belegt !== null) {
            return response()->json([
                'error' => [
                    'code' => Response::HTTP_CONFLICT,
                    'message' => sprintf(
                        'An einer der beiden Fahrten hängt bereits eine Entscheidung (%s). Erst lösen, dann neu setzen.',
                        TripLinkKind::from($belegt->kind)->label(),
                    ),
                ],
            ], Response::HTTP_CONFLICT);
        }

        $link = $this->links->create($kind, $von, $nach, $request->note());

        return TripLinkResource::make($this->links->describe($link))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** DELETE /api/v1/admin/trip-links/{tripLink} */
    public function destroy(TripLink $tripLink): JsonResponse
    {
        $this->links->remove($tripLink);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function existingDecision(?int $fromId, ?int $toId): ?object
    {
        $query = DB::table('trip_links');

        if ($fromId !== null && $toId !== null) {
            $query->where(function ($q) use ($fromId, $toId): void {
                $q->where('from_trip_id', $fromId)->orWhere('to_trip_id', $toId);
            });
        } elseif ($fromId !== null) {
            $query->where('from_trip_id', $fromId);
        } elseif ($toId !== null) {
            $query->where('to_trip_id', $toId);
        } else {
            return null;
        }

        return $query->first();
    }
}
