<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\StopGroupOrigin;
use App\Http\Controllers\Controller;
use App\Http\Requests\StopGroupAssignRequest;
use App\Http\Requests\StopGroupListRequest;
use App\Http\Requests\StopGroupMergeRequest;
use App\Http\Requests\StopGroupRequest;
use App\Http\Resources\StopGroupResource;
use App\Models\StopGroup;
use App\Services\StopGroupDirectoryService;
use App\Services\StopGroupService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pflege der **Haltestellen** — der Klammer um die Halte, die im Betrieb derselbe Ort sind.
 *
 * Die Automatik gruppiert über den normalisierten Namen und deckt damit den Regelfall ab.
 * Diese Endpunkte sind für den Rest: „Rothensee" und „Rothensee (Schleife)" sind dieselbe
 * Haltestelle, heißen aber verschieden — das kann nur ein Mensch entscheiden.
 */
final class StopGroupController extends Controller
{
    public function __construct(
        private readonly StopGroupDirectoryService $directory,
        private readonly StopGroupService $groups,
    ) {}

    /** GET /api/v1/admin/stop-groups?q=&only_termini= */
    public function index(StopGroupListRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->directory->overview($request->suche(), $request->onlyTermini()),
        ]);
    }

    /** GET /api/v1/admin/stop-groups/{stopGroup} */
    public function show(StopGroup $stopGroup): JsonResponse
    {
        $uebersicht = $this->directory->overview();
        $treffer = array_values(array_filter($uebersicht, static fn (array $g): bool => $g['id'] === $stopGroup->id));

        return response()->json([
            'data' => ($treffer[0] ?? []) + ['suggestions' => $this->groups->suggestionsFor($stopGroup)],
        ]);
    }

    /** POST /api/v1/admin/stop-groups */
    public function store(StopGroupRequest $request): JsonResponse
    {
        $gruppe = StopGroup::query()->create($request->validated() + [
            // Eine von Hand angelegte Haltestelle trägt keinen Namensschlüssel: Sie soll
            // nicht bei der nächsten Automatik fremde Halte einsammeln.
            'name_key' => null,
            'created_via' => StopGroupOrigin::Manual,
        ]);

        return StopGroupResource::make($gruppe)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** PUT /api/v1/admin/stop-groups/{stopGroup} */
    public function update(StopGroupRequest $request, StopGroup $stopGroup): StopGroupResource
    {
        $stopGroup->update($request->validated());

        return StopGroupResource::make($stopGroup);
    }

    /** POST /api/v1/admin/stop-groups/{stopGroup}/stops — Halt dieser Haltestelle zuordnen */
    public function assign(StopGroupAssignRequest $request, StopGroup $stopGroup): JsonResponse
    {
        $this->groups->assign($request->stopId(), $stopGroup);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** DELETE /api/v1/admin/stop-groups/{stopGroup}/stops/{stop} — Zuordnung aufheben */
    public function detach(StopGroup $stopGroup, int $stop): JsonResponse
    {
        $this->groups->detach($stop);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** POST /api/v1/admin/stop-groups/{stopGroup}/merge — andere Haltestelle hier einfügen */
    public function merge(StopGroupMergeRequest $request, StopGroup $stopGroup): JsonResponse
    {
        $quelle = $request->source();

        if ($quelle->id === $stopGroup->id) {
            return response()->json([
                'error' => [
                    'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                    'message' => 'Eine Haltestelle kann nicht mit sich selbst zusammengelegt werden.',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->groups->merge($quelle, $stopGroup);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
