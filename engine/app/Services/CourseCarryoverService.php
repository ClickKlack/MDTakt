<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TripLinkKind;
use App\Models\CourseTrip;
use App\Models\LineVersion;
use App\Models\TripLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Überträgt gepflegte Kurse und Anschlüsse von einer Linien-Version auf ihre Nachfolgerin
 * (KURSE §2 K4).
 *
 * **Warum nicht automatisch beim Import:** Eine neue Version bedeutet geänderte Zeiten. Ob ein
 * Anschluss das überlebt, hängt an der Wendezeit — eine um zehn Minuten verschobene Abfahrt
 * kann ihn unmöglich machen. Eine stille Übernahme schriebe solche Fälle fort. Der Knopf macht
 * daraus eine datierte Entscheidung, und die Vorschau zeigt vorher, was sie bewirkt.
 *
 * **Was übertragen wird, und was nicht:**
 *
 * | Gegenstand | Verhalten |
 * |---|---|
 * | Kursnummer | wird übertragen, sobald es eine Partnerfahrt gibt — auch bei verschobener Zeit |
 * | Aus-/Einrücken | wird übertragen; die Entscheidung hängt an einer Fahrt allein |
 * | Anschluss **innerhalb** der Version | wird übertragen, wenn beide Enden eine Partnerfahrt haben |
 * | Anschluss zu einer **anderen** Linie | bleibt liegen — siehe unten |
 *
 * Ein Anschluss auf eine Fahrt außerhalb dieser Version lässt sich nicht kopieren: Die
 * Gegenfahrt ist bereits mit der alten Fahrt verknüpft, und ein Fahrzeug hat höchstens einen
 * Vorgänger. Ihn zu *verschieben* wäre die Alternative — das nähme der alten Version aber ihre
 * Kette, die für ihre verbliebenen Gültigkeitstage weiter stimmt. Deshalb wird er gemeldet und
 * nach dem Wechsel von Hand neu gesetzt. Genau das ist der Linienwechsel-Fall (1 → 13), also
 * kein Randfall; sollte er im Betrieb häufig auftreten, ist hier der Ort, das zu überdenken.
 */
final class CourseCarryoverService
{
    public function __construct(
        private readonly LineVersionDiffService $diff,
        private readonly ConsolidatedTripInfoResolver $tripInfo,
    ) {}

    /**
     * Was eine Übernahme bewirken würde — ohne etwas zu ändern.
     *
     * @return array<string, mixed>
     */
    public function preview(LineVersion $from, LineVersion $to): array
    {
        return $this->run($from, $to, anwenden: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(LineVersion $from, LineVersion $to): array
    {
        $ergebnis = DB::transaction(fn (): array => $this->run($from, $to, anwenden: true));

        Log::info('Course carryover applied', [
            'from_line_version_id' => $from->id,
            'to_line_version_id' => $to->id,
            'line' => $from->line,
        ] + $ergebnis['summary']);

        return $ergebnis;
    }

    /**
     * @return array<string, mixed>
     */
    private function run(LineVersion $from, LineVersion $to, bool $anwenden): array
    {
        $paarung = $this->diff->pairing($from, $to);
        $paare = $paarung['pairs'];

        $alteIds = array_keys($paare);

        $kurse = $this->coursesOf($alteIds);
        $belegt = $this->existingAssignments(array_column($paare, 'to_trip_id'));

        $kursPlan = [];
        $kursZaehler = [];

        foreach ($paare as $altId => $paar) {
            $kursId = $kurse[$altId] ?? null;

            // Kein Kurs zu übertragen, oder die neue Fahrt trägt schon einen — dann wäre
            // Überschreiben eine Behauptung, keine Übernahme.
            if ($kursId === null || isset($belegt[$paar['to_trip_id']])) {
                continue;
            }

            $kursPlan[$paar['to_trip_id']] = $kursId;
            $kursZaehler[$kursId] = ($kursZaehler[$kursId] ?? 0) + 1;
        }

        [$linkPlan, $blockiert] = $this->planLinks($from, $paare);

        $verloren = $this->decisionsOn($paarung['removed']);

        if ($anwenden) {
            $this->writeCourses($kursPlan);
            $this->writeLinks($linkPlan);
        }

        return [
            'from' => $this->versionInfo($from),
            'to' => $this->versionInfo($to),
            'summary' => [
                'paired' => count($paare),
                'unchanged' => count(array_filter($paare, static fn (array $p): bool => $p['status'] === 'unchanged')),
                'changed' => count(array_filter($paare, static fn (array $p): bool => $p['status'] === 'changed')),
                'added' => count($paarung['added']),
                'removed' => count($paarung['removed']),
                'courses_carried' => count($kursPlan),
                'course_numbers' => count($kursZaehler),
                'links_carried' => count(array_filter($linkPlan, static fn (array $l): bool => $l['kind'] === TripLinkKind::Link->value)),
                'terminals_carried' => count(array_filter($linkPlan, static fn (array $l): bool => $l['kind'] !== TripLinkKind::Link->value)),
                'blocked' => count($blockiert),
                'lost' => count($verloren),
            ],
            'blocked' => $blockiert,
            'lost' => $verloren,
        ];
    }

    /**
     * Welche Entscheidungen der alten Version lassen sich übertragen?
     *
     * @param  array<int, array{to_trip_id: int, status: string}>  $paare
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function planLinks(LineVersion $from, array $paare): array
    {
        $alteIds = array_keys($paare);

        if ($alteIds === []) {
            return [[], []];
        }

        $entscheidungen = TripLink::query()
            ->where(function ($q) use ($alteIds): void {
                $q->whereIn('from_trip_id', $alteIds)->orWhereIn('to_trip_id', $alteIds);
            })
            ->get();

        $plan = [];
        $blockiert = [];

        foreach ($entscheidungen as $link) {
            $von = $link->from_trip_id;
            $nach = $link->to_trip_id;

            $vonNeu = $von === null ? null : ($paare[$von]['to_trip_id'] ?? null);
            $nachNeu = $nach === null ? null : ($paare[$nach]['to_trip_id'] ?? null);

            // Aus- und Einrücken hängen an einer Fahrt allein — sie lassen sich immer
            // übertragen, sobald diese Fahrt eine Nachfolgerin hat.
            if ($link->kind !== TripLinkKind::Link) {
                $ziel = $link->kind === TripLinkKind::Start ? $nachNeu : $vonNeu;

                if ($ziel === null) {
                    continue;
                }

                $plan[] = [
                    'kind' => $link->kind->value,
                    'from_trip_id' => $link->kind === TripLinkKind::End ? $ziel : null,
                    'to_trip_id' => $link->kind === TripLinkKind::Start ? $ziel : null,
                    'stop_id' => $link->stop_id,
                    'note' => $link->note,
                ];

                continue;
            }

            // Ein Anschluss, dessen Gegenfahrt außerhalb dieser Version liegt: Die Gegenfahrt
            // hängt bereits an der alten Fahrt, und ein Fahrzeug hat höchstens einen Vorgänger.
            if ($vonNeu === null || $nachNeu === null) {
                $aussen = $vonNeu === null ? $von : $nach;

                $blockiert[] = [
                    'trip' => $this->describeTrip($vonNeu ?? $nachNeu),
                    'partner' => $this->describeTrip($aussen),
                    'reason' => 'Der Anschlusspartner liegt außerhalb dieser Version und hängt noch an der alten Fahrt. '
                        .'Nach dem Wechsel von Hand neu setzen.',
                ];

                continue;
            }

            $plan[] = [
                'kind' => TripLinkKind::Link->value,
                'from_trip_id' => $vonNeu,
                'to_trip_id' => $nachNeu,
                'stop_id' => $link->stop_id,
                'note' => $link->note,
            ];
        }

        return [$plan, $blockiert];
    }

    /**
     * @param  array<int, int>  $kursPlan  neue Fahrt-Id => course_id
     */
    private function writeCourses(array $kursPlan): void
    {
        foreach ($kursPlan as $tripId => $courseId) {
            CourseTrip::query()->updateOrCreate(
                ['consolidated_trip_id' => $tripId],
                ['course_id' => $courseId],
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan
     */
    private function writeLinks(array $plan): void
    {
        foreach ($plan as $eintrag) {
            // Trägt eine der beiden Fahrten schon eine Entscheidung, gewinnt die bestehende:
            // Sie wurde nach dem Wechsel gesetzt und ist damit die jüngere Aussage.
            $belegt = TripLink::query()
                ->where(function ($q) use ($eintrag): void {
                    if ($eintrag['from_trip_id'] !== null) {
                        $q->orWhere('from_trip_id', $eintrag['from_trip_id']);
                    }
                    if ($eintrag['to_trip_id'] !== null) {
                        $q->orWhere('to_trip_id', $eintrag['to_trip_id']);
                    }
                })
                ->exists();

            if ($belegt) {
                continue;
            }

            TripLink::query()->create($eintrag);
        }
    }

    /**
     * @param  array<int, int>  $tripIds
     * @return array<int, int> Fahrt-Id => course_id
     */
    private function coursesOf(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        return CourseTrip::query()
            ->whereIn('consolidated_trip_id', $tripIds)
            ->pluck('course_id', 'consolidated_trip_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>  $tripIds
     * @return array<int, bool>
     */
    private function existingAssignments(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        return CourseTrip::query()
            ->whereIn('consolidated_trip_id', $tripIds)
            ->pluck('consolidated_trip_id')
            ->mapWithKeys(static fn ($id): array => [(int) $id => true])
            ->all();
    }

    /**
     * Entscheidungen an Fahrten, die es in der neuen Version nicht mehr gibt.
     *
     * @param  array<int, int>  $tripIds
     * @return array<int, array<string, mixed>>
     */
    private function decisionsOn(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $links = TripLink::query()
            ->where(function ($q) use ($tripIds): void {
                $q->whereIn('from_trip_id', $tripIds)->orWhereIn('to_trip_id', $tripIds);
            })
            ->get();

        return $links->map(fn (TripLink $link): array => [
            'kind' => $link->kind->value,
            'trip' => $this->describeTrip($link->from_trip_id ?? $link->to_trip_id),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describeTrip(?int $tripId): ?array
    {
        if ($tripId === null) {
            return null;
        }

        return $this->tripInfo->forIds([$tripId])[$tripId] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function versionInfo(LineVersion $version): array
    {
        return [
            'id' => $version->id,
            'line' => $version->line,
            'day_type' => $version->day_type->value,
            'day_type_label' => $version->day_type->label(),
            'version_no' => $version->version_no,
        ];
    }
}
