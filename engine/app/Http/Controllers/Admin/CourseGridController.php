<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseFilterRequest;
use App\Http\Resources\CourseGridResource;
use App\Services\CourseGridService;

/**
 * Die Umläufe einer Linie als klassische Tabelle — Halte als Zeilen, ein Kurs je Spalte.
 *
 * Eigener Endpunkt statt eines Schalters an der Kursübersicht: Die Antwort hat eine andere
 * Form (Achse und Zellen statt Ketten), und sie wiegt deutlich mehr. Wer die Ketten will, soll
 * die Tabelle nicht mitladen müssen.
 */
final class CourseGridController extends Controller
{
    public function __construct(private readonly CourseGridService $grid) {}

    /** GET /api/v1/admin/lines/{line}/course-grid?period=&day_type=&stand= */
    public function index(CourseFilterRequest $request, string $line): CourseGridResource
    {
        return CourseGridResource::make(
            $this->grid->forLine($line, $request->period(), $request->dayType(), $request->standIndex())
        );
    }
}
