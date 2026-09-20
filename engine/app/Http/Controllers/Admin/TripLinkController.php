<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\TripLinkKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\TripLinkRequest;
use App\Http\Resources\TripLinkResource;
use App\Models\TripLink;
use App\Services\CourseService;
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
    public function __construct(
        private readonly TripLinkService $links,
        private readonly CourseService $courses,
    ) {}

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

        // Zwei verknuepfte Fahrten sind dasselbe Fahrzeug — also derselbe Kurs. Traegt eine
        // Seite bereits eine Nummer, gilt sie ab jetzt fuer die ganze Kette; das von Hand
        // nachzutragen waere Arbeit, die aus der Verknuepfung schon folgt (KURSE §2 K2).
        $kurs = $kind === TripLinkKind::Link && $von !== null
            ? $this->courses->unifyChain($von)
            : ['course' => null, 'trips_assigned' => 0, 'conflict' => false];

        $daten = $this->links->describe($link->refresh());

        if ($kurs['conflict']) {
            // Welcher der beiden Kurse der richtige ist, weiss nur der Pflegende — also
            // melden statt raten. Die Verknuepfung selbst bleibt bestehen: Sie ist eine
            // Aussage ueber das Fahrzeug, der Kurs nur sein Etikett.
            $daten['warnings'][] = [
                'code' => 'course_conflict',
                'message' => 'Beide Ketten tragen bereits verschiedene Kursnummern. '
                    .'Trage die richtige ein — sie gilt dann für den ganzen Umlauf.',
            ];
        }

        $daten['course'] = $kurs['course'] === null ? null : $this->courses->describe($kurs['course']);
        $daten['course_trips_assigned'] = $kurs['trips_assigned'];

        return TripLinkResource::make($daten)->response()->setStatusCode(Response::HTTP_CREATED);
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
