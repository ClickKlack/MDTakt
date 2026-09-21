<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseSequenceRequest;
use App\Http\Resources\CourseSequenceResource;
use App\Models\LineVersion;
use App\Services\CourseSequenceService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kursnummern über einen Spaltenbereich der Fahrplan-Matrix fortschreiben oder entfernen.
 *
 * Bewusst zwei Endpunkte statt eines Schalters: Die Vorschau ist garantiert folgenlos, und erst
 * das POST ändert etwas. Wer die Vorschau aufruft, soll sich darauf verlassen können.
 */
final class CourseSequenceController extends Controller
{
    public function __construct(private readonly CourseSequenceService $sequence) {}

    /** GET /api/v1/admin/line-versions/{lineVersion}/course-sequence */
    public function show(CourseSequenceRequest $request, LineVersion $lineVersion): CourseSequenceResource|JsonResponse
    {
        $daten = $this->sequence->preview(
            $lineVersion,
            $request->integer('from_trip_id'),
            $request->integer('to_trip_id'),
            $request->action(),
            $request->pattern(),
        );

        return $daten['direction'] === null
            ? $this->richtungsFehler()
            : CourseSequenceResource::make($daten);
    }

    /** POST /api/v1/admin/line-versions/{lineVersion}/course-sequence */
    public function store(CourseSequenceRequest $request, LineVersion $lineVersion): CourseSequenceResource|JsonResponse
    {
        $von = $request->integer('from_trip_id');
        $bis = $request->integer('to_trip_id');

        // Erst die Vorschau: Liegen die Markierungen in verschiedenen Richtungen, darf gar
        // nichts geschrieben werden — und die Begründung ist dieselbe wie beim GET.
        $vorschau = $this->sequence->preview($lineVersion, $von, $bis, $request->action(), $request->pattern());

        if ($vorschau['direction'] === null) {
            return $this->richtungsFehler();
        }

        return CourseSequenceResource::make(
            $this->sequence->apply($lineVersion, $von, $bis, $request->action(), $request->pattern())
        );
    }

    private function richtungsFehler(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message' => 'Die beiden markierten Fahrten gehören zu verschiedenen Richtungen. '
                    .'Eine Nummernfolge läuft innerhalb einer Richtung um — markiere Start und Ende in derselben Tabelle.',
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
