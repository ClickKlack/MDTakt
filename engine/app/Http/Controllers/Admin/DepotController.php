<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DepotRequest;
use App\Http\Resources\DepotResource;
use App\Models\Depot;
use App\Services\DepotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pflege der Betriebshöfe (KURSE §3.2).
 *
 * Magdeburg hat zwei, und beide stehen nach der Migration schon da — die Endpunkte sind für
 * alles, was sich danach ändert: eine Umbenennung, ein dritter Hof, eine Stilllegung.
 */
final class DepotController extends Controller
{
    public function __construct(private readonly DepotService $depots) {}

    /** GET /api/v1/admin/depots?active_only= */
    public function index(Request $request): JsonResponse
    {
        $nurAktive = in_array((string) $request->query('active_only', ''), ['1', 'true'], true);

        return response()->json(['data' => $this->depots->directory($nurAktive)]);
    }

    /** POST /api/v1/admin/depots */
    public function store(DepotRequest $request): JsonResponse
    {
        $hof = $this->depots->create($request->daten(), $request->stopGroupIds());

        return DepotResource::make($this->depots->describe($hof, 0))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** PUT /api/v1/admin/depots/{depot} */
    public function update(DepotRequest $request, Depot $depot): DepotResource
    {
        $hof = $this->depots->update($depot, $request->daten(), $request->stopGroupIds());

        return DepotResource::make($this->depots->describe($hof));
    }

    /** DELETE /api/v1/admin/depots/{depot} */
    public function destroy(Depot $depot): JsonResponse
    {
        // Hängt eine Entscheidung daran, wird nicht gelöscht: Die Angabe „ausgerückt aus Nord"
        // ginge sonst still verloren. Stilllegen lässt den Hof aus der Auswahl verschwinden und
        // an den bestehenden Entscheidungen stehen — das ist hier der richtige Weg.
        if ($this->depots->isInUse($depot)) {
            return response()->json([
                'error' => [
                    'code' => Response::HTTP_CONFLICT,
                    'message' => 'An diesem Betriebshof hängen noch Entscheidungen. '
                        .'Leg ihn still, statt ihn zu löschen — dann verschwindet er aus der Auswahl, '
                        .'bleibt an den bestehenden Fahrten aber lesbar.',
                ],
            ], Response::HTTP_CONFLICT);
        }

        $this->depots->delete($depot);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
