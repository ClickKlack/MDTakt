<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use App\Models\ConsolidatedTrip;
use App\Models\Course;
use App\Models\CourseTrip;
use App\Models\SchedulePeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Die Kursnummer als **Etikett am Umlauf** (KURSE §2 K2).
 *
 * Gesetzt wird sie immer für die ganze Kette, nie für eine einzelne Fahrt: Ein Fahrzeug trägt
 * seine Nummer über den gesamten Betriebstag, auch über Linienwechsel hinweg — eine 1 wird in
 * Sudenburg zur 13 und heißt dann `13/03` statt `1/03` (K1).
 *
 * **Keine Eindeutigkeitsprüfung.** Ob eine Kursnummer netzweit eindeutig ist oder nur je Linie,
 * ist offen (K3). Zwei Umläufe mit derselben Nummer werden deshalb gemeldet, nicht verhindert —
 * ein zu früh gesetzter Unique-Index blockierte später echte Fälle, die Meldung dagegen findet
 * Tippfehler und lässt sich folgenlos ignorieren.
 */
final class CourseService
{
    public function __construct(
        private readonly TripLinkService $links,
        private readonly CourseLookup $lookup,
        private readonly ConsolidatedTripTimeResolver $tripTimes,
        private readonly OperatingDayResolver $operatingDay,
    ) {}

    /**
     * Setzt den Kurs für die **ganze Kette**, zu der diese Fahrt gehört.
     *
     * @return array{course: Course, trips_assigned: int, trip_ids: array<int, int>}
     */
    public function assign(ConsolidatedTrip $trip, Course $course): array
    {
        $kette = $this->links->chainFor($trip);

        DB::transaction(function () use ($kette, $course): void {
            foreach ($kette as $tripId) {
                CourseTrip::query()->updateOrCreate(
                    ['consolidated_trip_id' => $tripId],
                    ['course_id' => $course->id],
                );
            }
        });

        Log::info('Course assigned to chain', [
            'course_id' => $course->id,
            'course_number' => $course->number,
            'trip_id' => $trip->id,
            'chain_length' => count($kette),
        ]);

        return ['course' => $course, 'trips_assigned' => count($kette), 'trip_ids' => $kette];
    }

    /**
     * Nimmt den Kurs von der ganzen Kette. Der Umlauf selbst bleibt bestehen — er kann erneut
     * vergeben werden, und seine Nummer ist nicht verbraucht.
     *
     * @return array{course: null, trips_assigned: int, trip_ids: array<int, int>}
     */
    public function detach(ConsolidatedTrip $trip): array
    {
        $kette = $this->links->chainFor($trip);

        $geloest = CourseTrip::query()->whereIn('consolidated_trip_id', $kette)->delete();

        Log::info('Course detached from chain', [
            'trip_id' => $trip->id,
            'chain_length' => count($kette),
            'removed' => $geloest,
        ]);

        return ['course' => null, 'trips_assigned' => count($kette), 'trip_ids' => $kette];
    }

    /**
     * Gleicht den Kurs über die ganze Kette an, nachdem sie verändert wurde.
     *
     * Verknüpft man zwei Fahrten, sagt man damit: **dasselbe Fahrzeug**. Dann kann es nur eine
     * Kursnummer geben — trägt die eine Seite bereits eine und die andere nicht, gilt sie ab
     * jetzt für beide. Das von Hand nachzutragen wäre Arbeit, die aus der Verknüpfung schon
     * folgt.
     *
     * Tragen **beide** Seiten einen Kurs, und zwar verschiedene, wird nichts überschrieben:
     * Welcher der richtige ist, weiß nur der Pflegende. Der Widerspruch wird gemeldet
     * (`conflict`), damit er nicht unbemerkt stehen bleibt.
     *
     * @return array{course: Course|null, trips_assigned: int, conflict: bool}
     */
    public function unifyChain(ConsolidatedTrip $trip): array
    {
        $kette = $this->links->chainFor($trip);
        $vorhandene = $this->lookup->forTrips($kette);

        $ids = array_values(array_unique(array_column($vorhandene, 'id')));

        if ($ids === []) {
            return ['course' => null, 'trips_assigned' => 0, 'conflict' => false];
        }

        if (count($ids) > 1) {
            Log::warning('Chain carries conflicting courses', [
                'trip_id' => $trip->id,
                'course_ids' => $ids,
                'chain_length' => count($kette),
            ]);

            return ['course' => null, 'trips_assigned' => 0, 'conflict' => true];
        }

        $kurs = Course::query()->find($ids[0]);

        if ($kurs === null) {
            return ['course' => null, 'trips_assigned' => 0, 'conflict' => false];
        }

        // Schon vollständig belegt: nichts zu tun, und die Meldung bliebe sonst irreführend.
        if (count($vorhandene) === count($kette)) {
            return ['course' => $kurs, 'trips_assigned' => 0, 'conflict' => false];
        }

        $this->assign($trip, $kurs);

        return [
            'course' => $kurs,
            'trips_assigned' => count($kette) - count($vorhandene),
            'conflict' => false,
        ];
    }

    /**
     * Findet den Kurs mit dieser Nummer **für diese Kette** oder legt ihn an.
     *
     * Der übliche Weg aus der Oberfläche: Wer eine Nummer am Fahrzeug abliest, will sie
     * eintippen und nicht erst einen Kurs anlegen.
     *
     * **Die Nummer ist nur je Linie eindeutig, nicht netzweit** (KURSE §2 K3). Die „2" der
     * Linie 8 ist ein anderer Umlauf als die „2" der Linie 6. Gesucht wird deshalb nur unter
     * den Kursen dieser Nummer, die mindestens eine Linie mit der Kette gemeinsam haben.
     *
     * Warum Überschneidung und nicht Gleichheit der Linienmengen: Eine Kette wächst. Heute
     * liegt Kurs 1/03 nur auf der 1, morgen hängt eine 13er-Fahrt daran. Verlangte man eine
     * exakte Übereinstimmung, entstünde beim nächsten Eintippen auf der 1 ein zweiter Kurs
     * mit derselben Nummer.
     *
     * Ein Kurs ohne Fahrten ist noch an keine Linie gebunden und nimmt jede auf.
     */
    public function findOrCreateForChain(ConsolidatedTrip $trip, string $number): Course
    {
        $version = $trip->lineVersion;
        $eigeneLinien = $this->linesOfTrips($this->links->chainFor($trip));

        $kandidaten = Course::query()
            ->where('period_id', $version->period_id)
            ->where('day_type', $version->day_type->value)
            ->where('number', $number)
            ->get();

        foreach ($kandidaten as $kurs) {
            $kursLinien = $this->linesOfCourse($kurs->id);

            if ($kursLinien === [] || array_intersect($kursLinien, $eigeneLinien) !== []) {
                return $kurs;
            }
        }

        return Course::query()->create([
            'period_id' => $version->period_id,
            'day_type' => $version->day_type->value,
            'number' => $number,
        ]);
    }

    /**
     * Die Linien, die eine Menge von Fahrten berührt.
     *
     * @param  array<int, int>  $tripIds
     * @return array<int, string>
     */
    private function linesOfTrips(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        return DB::table('consolidated_trips as ct')
            ->join('line_versions as lv', 'lv.id', '=', 'ct.line_version_id')
            ->whereIn('ct.id', $tripIds)
            ->distinct()
            ->pluck('lv.line')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function linesOfCourse(int $courseId): array
    {
        return DB::table('course_trips as k')
            ->join('consolidated_trips as ct', 'ct.id', '=', 'k.consolidated_trip_id')
            ->join('line_versions as lv', 'lv.id', '=', 'ct.line_version_id')
            ->where('k.course_id', $courseId)
            ->distinct()
            ->pluck('lv.line')
            ->all();
    }

    /**
     * Die Umläufe eines Strangs samt Linien und Eckzeiten.
     *
     * `$line` schränkt auf Umläufe ein, die diese Linie **berühren** — ein Umlauf kann mehrere
     * Linien umfassen und erscheint dann bei jeder von ihnen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overview(SchedulePeriod $period, FahrplanTyp $typ, ?string $line = null): array
    {
        $kurse = Course::query()
            ->where('period_id', $period->id)
            ->where('day_type', $typ->value)
            ->get();

        if ($kurse->isEmpty()) {
            return [];
        }

        $fahrten = DB::table('course_trips as k')
            ->join('consolidated_trips as ct', 'ct.id', '=', 'k.consolidated_trip_id')
            ->join('line_versions as lv', 'lv.id', '=', 'ct.line_version_id')
            ->whereIn('k.course_id', $kurse->pluck('id'))
            ->select('k.course_id', 'ct.id as trip_id', 'lv.line')
            ->get()
            ->groupBy('course_id');

        $zeiten = $this->tripTimes->endpoints(
            $fahrten->flatten(1)->pluck('trip_id')->map(static fn ($id): int => (int) $id)->all()
        );

        // Eine Dublette ist dieselbe Nummer auf **denselben Linien** — nicht schon dieselbe
        // Nummer im Strang: Die „2" der Linie 8 ist ein anderer Umlauf als die „2" der Linie 6
        // (K3). Erst wenn sich die Linien überschneiden, widersprechen sich zwei Kurse.
        $linienJeKurs = $fahrten
            ->map(static fn ($zeilen): array => $zeilen->pluck('line')->unique()->values()->all())
            ->all();

        $ergebnis = [];

        foreach ($kurse as $kurs) {
            $eigene = $fahrten->get($kurs->id, collect());
            $linien = $eigene->pluck('line')->unique()->values();

            if ($line !== null && ! $linien->contains($line)) {
                continue;
            }

            $sortiert = $eigene
                ->map(fn (object $f): array => [
                    'line' => $f->line,
                    'departure' => $zeiten[$f->trip_id]['departure'] ?? null,
                    'arrival' => $zeiten[$f->trip_id]['arrival'] ?? null,
                ])
                // Entlang des Betriebstags, nicht der Uhr — auf der N1 beginnt der Tag abends.
                ->sortBy(fn (array $f): int => $this->operatingDay->sortKey($f['line'], $f['departure']))
                ->values();

            $linienListe = $linien->sort(SORT_NATURAL)->values()->all();

            $ergebnis[] = [
                'id' => $kurs->id,
                'period_id' => $kurs->period_id,
                'day_type' => $kurs->day_type->value,
                'day_type_label' => $kurs->day_type->label(),
                'number' => $kurs->number,
                'note' => $kurs->note,
                'trip_count' => $eigene->count(),
                'lines' => $linienListe,
                'first_departure' => $sortiert->first()['departure'] ?? null,
                'last_arrival' => $sortiert->last()['arrival'] ?? null,
                'duplicate' => $this->hasOverlappingTwin($kurs, $kurse, $linienJeKurs),
            ];
        }

        usort($ergebnis, static fn (array $a, array $b): int => strnatcmp($a['number'], $b['number']));

        return $ergebnis;
    }

    /**
     * Trägt ein anderer Umlauf dieselbe Nummer auf einer gemeinsamen Linie?
     *
     * @param  Collection<int, Course>  $alle
     * @param  array<int, array<int, string>>  $linienJeKurs
     */
    private function hasOverlappingTwin(Course $kurs, $alle, array $linienJeKurs): bool
    {
        foreach ($alle as $anderer) {
            if ($anderer->id === $kurs->id || $anderer->number !== $kurs->number) {
                continue;
            }

            $a = $linienJeKurs[$kurs->id] ?? [];
            $b = $linienJeKurs[$anderer->id] ?? [];

            if ($a === [] || $b === [] || array_intersect($a, $b) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ein einzelner Umlauf in derselben Form wie in der Übersicht.
     *
     * @return array<string, mixed>
     */
    public function describe(Course $course): array
    {
        $alle = $this->overview(
            $course->period ?? SchedulePeriod::query()->findOrFail($course->period_id),
            $course->day_type,
        );

        foreach ($alle as $eintrag) {
            if ($eintrag['id'] === $course->id) {
                return $eintrag;
            }
        }

        // Ein Umlauf ohne Fahrten taucht in der Übersicht auf, sobald er welche hat — frisch
        // angelegt ist er leer und wird hier trotzdem beschrieben.
        return [
            'id' => $course->id,
            'period_id' => $course->period_id,
            'day_type' => $course->day_type->value,
            'day_type_label' => $course->day_type->label(),
            'number' => $course->number,
            'note' => $course->note,
            'trip_count' => 0,
            'lines' => [],
            'first_departure' => null,
            'last_arrival' => null,
            'duplicate' => $this->hasOverlappingTwin(
                $course,
                Course::query()
                    ->where('period_id', $course->period_id)
                    ->where('day_type', $course->day_type->value)
                    ->where('number', $course->number)
                    ->get(),
                $this->linesPerCourseFor($course),
            ),
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function linesPerCourseFor(Course $course): array
    {
        $ids = Course::query()
            ->where('period_id', $course->period_id)
            ->where('day_type', $course->day_type->value)
            ->where('number', $course->number)
            ->pluck('id');

        $ergebnis = [];

        foreach ($ids as $id) {
            $ergebnis[(int) $id] = $this->linesOfCourse((int) $id);
        }

        return $ergebnis;
    }

    /**
     * Wendezeit-freie Prüfung, ob Kurs und Fahrt in denselben Strang gehören.
     *
     * Ein Kurs gehört zu einer Periode und einem Fahrplantyp; eine Fahrt über ihre
     * Linien-Version ebenso. Passen sie nicht zusammen, wäre die Zuordnung an keinem Tag
     * wirksam.
     */
    public function matchesStrand(Course $course, ConsolidatedTrip $trip): bool
    {
        $version = $trip->lineVersion;

        return $course->period_id === $version->period_id
            && $course->day_type === $version->day_type;
    }
}
