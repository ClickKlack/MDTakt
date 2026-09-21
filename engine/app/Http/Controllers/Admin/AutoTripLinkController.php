<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutoTripLinkRequest;
use App\Http\Resources\AutoTripLinkResource;
use App\Services\TripLinkAutoService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anschlüsse über einen Zeitraum automatisch verknüpfen oder auflösen.
 *
 * Bewusst zwei Endpunkte statt eines Schalters: Die Vorschau ist garantiert folgenlos, und erst
 * das POST ändert etwas. Wer die Vorschau aufruft, soll sich darauf verlassen können.
 */
final class AutoTripLinkController extends Controller
{
    public function __construct(private readonly TripLinkAutoService $auto) {}

    /** GET /api/v1/admin/stop-links/auto */
    public function show(AutoTripLinkRequest $request): AutoTripLinkResource|JsonResponse
    {
        $daten = $this->auto->preview($request->scope());

        return $this->antwort($daten, $request);
    }

    /** POST /api/v1/admin/stop-links/auto */
    public function store(AutoTripLinkRequest $request): AutoTripLinkResource|JsonResponse
    {
        $scope = $request->scope();

        // Erst die Vorschau: Liegt die Markierung nicht im Filter, darf gar nichts geschrieben
        // werden — und die Begründung ist dieselbe wie beim GET.
        $vorschau = $this->auto->preview($scope);

        if ($vorschau['range'] === null) {
            return $this->rangeFehler($request);
        }

        return AutoTripLinkResource::make($this->auto->apply($scope));
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    private function antwort(array $daten, AutoTripLinkRequest $request): AutoTripLinkResource|JsonResponse
    {
        if ($daten['range'] === null) {
            return $this->rangeFehler($request);
        }

        // Ein ausdrücklich angefragter Stand, den es nicht gibt, ist ein Fehler — wie im
        // Haltestellen-Editor selbst.
        if ($request->standIndex() !== null && $daten['stand'] === null) {
            return $this->fehler('Diesen Versionsstand gibt es an dieser Haltestelle nicht.');
        }

        return AutoTripLinkResource::make($daten);
    }

    private function rangeFehler(AutoTripLinkRequest $request): JsonResponse
    {
        // Kein leeres Ergebnis, sondern ein Fehler: Der Lauf bearbeitete sonst einen Ausschnitt,
        // den der Pflegende gar nicht vor sich hat.
        return $this->fehler(
            $request->lines() === [] && $request->mode() === null
                ? 'Mindestens eine der markierten Fahrten endet nicht an dieser Haltestelle in diesem Versionsstand.'
                : 'Mindestens eine der markierten Fahrten liegt nicht im aktiven Filter. '
                    .'Setz den Filter zurück oder markiere neu.',
        );
    }

    private function fehler(string $meldung): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message' => $meldung,
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
