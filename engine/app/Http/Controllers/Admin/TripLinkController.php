<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\TripLinkKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\TripLinkDepotRequest;
use App\Http\Requests\TripLinkRequest;
use App\Http\Resources\TripLinkResource;
use App\Models\TripLink;
use App\Services\CourseService;
use App\Services\TripLinkService;
use App\Services\TripLinkValidity;
use Illuminate\Http\JsonResponse;
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
        private readonly TripLinkValidity $validity,
    ) {}

    /** POST /api/v1/admin/trip-links */
    public function store(TripLinkRequest $request): JsonResponse
    {
        $kind = $request->kind();
        $von = $request->fromTrip();
        $nach = $request->toTrip();

        // Belegt heißt belegt — aber **je Tag**, nicht je Fahrt. Eine Fahrt darf mehrere
        // Anschlüsse tragen, solange sie an verschiedenen Tagen gelten: Wechselt eine
        // Nachbarlinie mitten in der Periode die Version, braucht dieselbe Fahrt ab dem
        // Wechseltag einen anderen Nachfolger (KURSE §2 K7).
        $belegt = $this->validity->conflictFor($von?->id, $nach?->id);

        if ($belegt !== null) {
            return response()->json([
                'error' => [
                    'code' => Response::HTTP_CONFLICT,
                    'message' => sprintf(
                        'An einer der beiden Fahrten hängt an denselben Tagen bereits eine Entscheidung (%s). '
                        .'Erst lösen, dann neu setzen.',
                        TripLinkKind::from($belegt->kind)->label(),
                    ),
                ],
            ], Response::HTTP_CONFLICT);
        }

        // `has('depot_id')` statt eines Werte-Vergleichs: Ein ausdrueckliches `null` heisst
        // „bewusst offen" und darf die Automatik nicht ausloesen. Fehlt das Feld ganz — der
        // uebliche Weg aus dem Board —, schlaegt die Haltestelle den Hof vor.
        $link = $this->links->create(
            $kind,
            $von,
            $nach,
            $request->note(),
            $request->depotId(),
            $request->has('depot_id'),
        );

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

    /** PUT /api/v1/admin/trip-links/{tripLink}/depot — Betriebshof setzen oder offen lassen */
    public function depot(TripLinkDepotRequest $request, TripLink $tripLink): TripLinkResource
    {
        $link = $this->links->setDepot($tripLink, $request->depotId());

        return TripLinkResource::make($this->links->describe($link) + [
            'course' => null,
            'course_trips_assigned' => 0,
        ]);
    }

    /** DELETE /api/v1/admin/trip-links/{tripLink} */
    public function destroy(TripLink $tripLink): JsonResponse
    {
        $this->links->remove($tripLink);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
