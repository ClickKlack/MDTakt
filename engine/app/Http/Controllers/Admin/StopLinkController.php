<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StopLinkBoardRequest;
use App\Http\Resources\StopLinkBoardResource;
use App\Services\StopLinkBoardService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Der Haltestellen-Editor: welche Fahrten enden hier, welche beginnen hier, was ist entschieden.
 */
final class StopLinkController extends Controller
{
    public function __construct(private readonly StopLinkBoardService $board) {}

    /** GET /api/v1/admin/stop-links?stop=&period=&day_type=&stand= */
    public function index(StopLinkBoardRequest $request): StopLinkBoardResource|JsonResponse
    {
        $daten = $this->board->board(
            $request->stopGroup(),
            $request->period(),
            $request->dayType(),
            $request->standIndex(),
        );

        // Ein ausdrücklich angefragter Stand, den es nicht gibt, ist ein Fehler — anders als
        // ein Halt ohne Fahrten, der schlicht leere Listen liefert.
        if ($request->standIndex() !== null && $daten['stand'] === null) {
            return response()->json([
                'error' => [
                    'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                    'message' => 'Diesen Versionsstand gibt es an dieser Haltestelle nicht.',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return StopLinkBoardResource::make($daten);
    }
}
