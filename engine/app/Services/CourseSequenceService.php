<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CourseSequenceAction;
use App\Models\ConsolidatedTrip;
use App\Models\Course;
use App\Models\LineVersion;
use App\Support\CourseNumberSequence;
use App\Support\TripChainGraph;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Schreibt eine erkannte Kursnummern-Folge über einen Spaltenbereich der Fahrplan-Matrix fort —
 * und nimmt sie wieder ab.
 *
 * Häufig läuft eine Linie ihre Kurse in fester chronologischer Abfolge: Die 6 fährt die Kurse
 * 1–8, und bei gleichbleibendem Takt setzt sich das fort. Kennt der Pflegende die Menge der
 * Nummern, soll er sie nicht Spalte für Spalte eintippen müssen.
 *
 * **Gearbeitet wird je Richtung.** Nur dort stehen die Fahrten in der Reihenfolge, in der die
 * Nummern umlaufen — über beide Richtungen hinweg wechselten sie einander ab und ergäben kein
 * Muster. Die Richtung ist deshalb kein Parameter, sondern folgt aus der markierten Startfahrt.
 *
 * **Geplant wird je Kette, nicht je Spalte.** Die Nummer ist ein Etikett am Umlauf (KURSE §2 K2),
 * und {@see CourseService::assign()} schreibt sie über die ganze Kette. Daraus folgt beides: Die
 * „trägt schon einen Kurs"-Prüfung muss auf Kettenebene liegen — auf Fahrtebene geprüft
 * überschriebe man still genau die Nummer, die geschont werden soll —, und eine Zuweisung zieht
 * Fahrten mit, die gar nicht markiert sind. Letztere stehen als `outside_range` im Ergebnis,
 * damit die Vorschau nicht weniger ankündigt, als sie tut.
 *
 * Aufbau wie {@see CourseCarryoverService}: Vorschau und Anwenden sind derselbe Lauf mit einem
 * Schalter, und die Vorschau ist garantiert folgenlos.
 */
final class CourseSequenceService
{
    public function __construct(
        private readonly TimetableService $timetable,
        private readonly TripLinkService $links,
        private readonly CourseService $courses,
        private readonly CourseLookup $lookup,
        private readonly ConsolidatedTripInfoResolver $tripInfo,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(
        LineVersion $version,
        int $fromTripId,
        int $toTripId,
        CourseSequenceAction $action,
        ?string $pattern,
    ): array {
        return $this->run($version, $fromTripId, $toTripId, $action, $pattern, anwenden: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(
        LineVersion $version,
        int $fromTripId,
        int $toTripId,
        CourseSequenceAction $action,
        ?string $pattern,
    ): array {
        $ergebnis = DB::transaction(
            fn (): array => $this->run($version, $fromTripId, $toTripId, $action, $pattern, anwenden: true)
        );

        Log::info('Course sequence applied', [
            'line_version_id' => $version->id,
            'line' => $version->line,
            'day_type' => $version->day_type->value,
            'action' => $action->value,
            'pattern' => $pattern,
        ] + $ergebnis['summary']);

        return $ergebnis;
    }

    /**
     * @return array<string, mixed>
     */
    private function run(
        LineVersion $version,
        int $fromTripId,
        int $toTripId,
        CourseSequenceAction $action,
        ?string $pattern,
        bool $anwenden,
    ): array {
        $richtung = $this->directionOf($version, $fromTripId, $toTripId);

        if ($richtung === null) {
            return $this->antwort($version, null, null, null, [], [], [], [], [], $action, $anwenden);
        }

        $spalten = $richtung['trips'];
        $bereich = $this->resolveRange($spalten, $fromTripId, $toTripId);

        $nummern = $action === CourseSequenceAction::Assign
            ? CourseNumberSequence::parse((string) $pattern)
            : [];

        // Einmal für die ganze Version: `chainFor()` fragt je Kettensprung die Datenbank, und
        // bei 120 Spalten wäre das vierstellig.
        $graph = $this->links->graphFor(array_column($spalten, 'id'));

        $plan = $this->planChains($bereich, $spalten, $nummern, $graph, $action);

        if ($anwenden) {
            $plan['eintraege'] = $this->write($plan['eintraege'], $action);
        }

        return $this->antwort(
            $version,
            $richtung,
            $bereich,
            $action === CourseSequenceAction::Assign ? ['input' => (string) $pattern, 'numbers' => $nummern, 'count' => count($nummern)] : null,
            $plan['eintraege'],
            $plan['unveraendert'],
            $plan['uebersprungen'],
            $plan['konflikte'],
            $plan['hinweise'],
            $action,
            $anwenden,
        );
    }

    /**
     * Die Richtung, in der **beide** Markierungen liegen.
     *
     * `null`, wenn sie in verschiedenen Richtungen stehen oder gar nicht zu dieser Version
     * gehören — der Controller macht daraus ein 422. Stillschweigend die Richtung der ersten zu
     * nehmen wäre schlimmer: Der Lauf bearbeitete dann einen Bereich, den niemand markiert hat.
     *
     * @return array<string, mixed>|null
     */
    private function directionOf(LineVersion $version, int $fromTripId, int $toTripId): ?array
    {
        foreach ($this->timetable->forVersion($version)['directions'] as $richtung) {
            $ids = array_column($richtung['trips'], 'id');

            if (in_array($fromTripId, $ids, true) && in_array($toTripId, $ids, true)) {
                return $richtung;
            }
        }

        return null;
    }

    /**
     * Die markierten Spalten und alles dazwischen — **inklusive** beider Grenzen.
     *
     * Maßgeblich ist die Spaltenreihenfolge der Matrix (entlang des Betriebstags, nicht der
     * Uhr). Genau die sieht der Pflegende, und genau in ihr läuft das Muster um.
     *
     * @param  array<int, array<string, mixed>>  $spalten
     * @return array<int, int> Spaltenindex => Fahrt-Id
     */
    private function resolveRange(array $spalten, int $fromTripId, int $toTripId): array
    {
        $von = null;
        $bis = null;

        foreach ($spalten as $i => $spalte) {
            if ($spalte['id'] === $fromTripId) {
                $von = $i;
            }
            if ($spalte['id'] === $toTripId) {
                $bis = $i;
            }
        }

        // Verkehrt herum markiert ist kein Fehler, sondern eine Handbewegung von rechts nach links.
        if ($von > $bis) {
            [$von, $bis] = [$bis, $von];
        }

        $bereich = [];

        for ($i = $von; $i <= $bis; $i++) {
            $bereich[$i] = $spalten[$i]['id'];
        }

        return $bereich;
    }

    /**
     * Faltet die Spalten auf Ketten und entscheidet je Kette, was geschieht.
     *
     * @param  array<int, int>  $bereich  Spaltenindex => Fahrt-Id
     * @param  array<int, array<string, mixed>>  $spalten
     * @param  array<int, string>  $nummern
     * @return array{eintraege: array<int, array<string, mixed>>, unveraendert: array<int, array<string, mixed>>, uebersprungen: array<int, array<string, mixed>>, konflikte: array<int, array<string, mixed>>, hinweise: array<int, array<string, mixed>>}
     */
    private function planChains(
        array $bereich,
        array $spalten,
        array $nummern,
        TripChainGraph $graph,
        CourseSequenceAction $action,
    ): array {
        $imBereich = array_flip($bereich);
        $ketten = [];
        $reihenfolge = [];
        $index = 0;

        foreach ($bereich as $spalte => $tripId) {
            // Zyklisch **ab der Startspalte**, nicht ab Spalte 0 der Richtung: Der Pflegende
            // sagt mit seiner Markierung, wo die Folge beginnt.
            $nummer = $nummern === [] ? null : $nummern[$index % count($nummern)];
            $index++;

            $kette = $graph->chainOf($tripId);
            // Die kleinste Fahrt-Id identifiziert die Kette — irgendein stabiler Vertreter muss
            // es sein, damit dieselbe Kette aus zwei Spalten heraus dieselbe bleibt.
            $schluessel = min($kette);

            if (! isset($ketten[$schluessel])) {
                $ketten[$schluessel] = ['column' => $spalte, 'trip_id' => $tripId, 'chain' => $kette, 'numbers' => []];
                $reihenfolge[] = $schluessel;
            }

            if ($nummer !== null) {
                $ketten[$schluessel]['numbers'][] = $nummer;
            }
        }

        $alleTripIds = [];

        foreach ($ketten as $kette) {
            $alleTripIds = [...$alleTripIds, ...$kette['chain']];
        }

        $info = $this->tripInfo->forIds($alleTripIds);
        $vorhandene = $this->lookup->forTrips($alleTripIds);

        $eintraege = [];
        $unveraendert = [];
        $uebersprungen = [];
        $konflikte = [];

        foreach ($reihenfolge as $schluessel) {
            $kette = $ketten[$schluessel];
            $fahrt = $info[$kette['trip_id']] ?? null;

            if ($fahrt === null) {
                continue;
            }

            $bestehend = $this->courseOfChain($kette['chain'], $vorhandene);

            if ($action === CourseSequenceAction::Clear) {
                if ($bestehend === null) {
                    $uebersprungen[] = $this->skip($kette['column'], null, $fahrt, 'no_course', 'Diese Fahrt gehört zu keinem Umlauf.');

                    continue;
                }

                $eintraege[] = $this->eintrag($kette, $bestehend['number'], $fahrt, $info, $imBereich);

                continue;
            }

            // Dieselbe Nummer mehrfach auf einer Kette ist der **Normalfall** eines zyklischen
            // Musters — das Fahrzeug kommt eben wieder. Erst zwei verschiedene widersprechen
            // sich, und die schrieben sich beim sequenziellen Anwenden gegenseitig weg.
            $verschiedene = array_values(array_unique($kette['numbers']));

            if (count($verschiedene) > 1) {
                $konflikte[] = [
                    'numbers' => $verschiedene,
                    'chain_trip_ids' => $kette['chain'],
                    'trips' => array_values(array_filter(array_map(
                        static fn (int $id): ?array => $info[$id] ?? null,
                        $kette['chain'],
                    ))),
                    'reason' => sprintf(
                        'Zwei Fahrten desselben Umlaufs sollen verschiedene Nummern bekommen (%s). '
                        .'Welche die richtige ist, weiß nur der Pflegende — deshalb wird hier nichts geschrieben.',
                        implode(' und ', $verschiedene),
                    ),
                ];

                continue;
            }

            $nummer = $verschiedene[0];

            if ($bestehend !== null) {
                if ($bestehend['number'] === $nummer) {
                    // Kein Fehler, sondern der Grund, warum ein zweiter Lauf folgenlos bleibt.
                    $unveraendert[] = ['column' => $kette['column'], 'number' => $nummer, 'trip' => $fahrt];

                    continue;
                }

                $uebersprungen[] = $this->skip(
                    $kette['column'],
                    $nummer,
                    $fahrt,
                    'already_assigned',
                    sprintf(
                        'Die Fahrt gehört zu einem Umlauf, der bereits Kurs %s trägt. Erst entfernen, dann neu setzen.',
                        $bestehend['number'],
                    ),
                );

                continue;
            }

            $eintraege[] = $this->eintrag($kette, $nummer, $fahrt, $info, $imBereich);
        }

        return [
            'eintraege' => $eintraege,
            'unveraendert' => $unveraendert,
            'uebersprungen' => $uebersprungen,
            'konflikte' => $konflikte,
            'hinweise' => $this->hinweise($eintraege, $vorhandene, $action),
        ];
    }

    /**
     * @param  array<int, int>  $kette
     * @param  array<int, array{id: int, number: string}>  $vorhandene
     * @return array{id: int, number: string}|null
     */
    private function courseOfChain(array $kette, array $vorhandene): ?array
    {
        foreach ($kette as $tripId) {
            if (isset($vorhandene[$tripId])) {
                return $vorhandene[$tripId];
            }
        }

        return null;
    }

    /**
     * @param  array{column: int, trip_id: int, chain: array<int, int>, numbers: array<int, string>}  $kette
     * @param  array<string, mixed>  $fahrt
     * @param  array<int, array<string, mixed>>  $info
     * @param  array<int, int>  $imBereich
     * @return array<string, mixed>
     */
    private function eintrag(array $kette, string $nummer, array $fahrt, array $info, array $imBereich): array
    {
        $ausserhalb = array_values(array_filter(array_map(
            static fn (int $id): ?array => isset($imBereich[$id]) ? null : ($info[$id] ?? null),
            $kette['chain'],
        )));

        return [
            'column' => $kette['column'],
            'number' => $nummer,
            'trip' => $fahrt,
            'chain_trip_ids' => $kette['chain'],
            'chain_trip_count' => count($kette['chain']),
            'outside_range' => $ausserhalb,
            'course' => null,
        ];
    }

    /**
     * Setzt oder entfernt die geplanten Nummern — immer über die Ketten-Semantik, nie roh in
     * `course_trips`.
     *
     * @param  array<int, array<string, mixed>>  $eintraege
     * @return array<int, array<string, mixed>>
     */
    private function write(array $eintraege, CourseSequenceAction $action): array
    {
        $geschrieben = [];

        foreach ($eintraege as $eintrag) {
            $fahrt = ConsolidatedTrip::query()->with('lineVersion')->find($eintrag['trip']['id']);

            if ($fahrt === null) {
                continue;
            }

            if ($action === CourseSequenceAction::Clear) {
                $this->courses->detach($fahrt);
                $geschrieben[] = $eintrag;

                continue;
            }

            // Linienbezogenes Nachschlagen (KURSE §2 K3): Ein bestehender Kurs wird nur
            // wiederverwendet, wenn er eine Linie mit der Kette gemeinsam hat.
            $kurs = $this->courses->findOrCreateForChain($fahrt, $eintrag['number']);

            if (! $this->courses->matchesStrand($kurs, $fahrt)) {
                continue;
            }

            $this->courses->assign($fahrt, $kurs);

            $eintrag['course'] = $this->courses->describe($kurs->refresh());
            $geschrieben[] = $eintrag;
        }

        return $geschrieben;
    }

    /**
     * Hinweise, die nichts verhindern.
     *
     * @param  array<int, array<string, mixed>>  $eintraege
     * @param  array<int, array{id: int, number: string}>  $vorhandene
     * @return array<int, array<string, mixed>>
     */
    private function hinweise(array $eintraege, array $vorhandene, CourseSequenceAction $action): array
    {
        if ($eintraege === []) {
            return [];
        }

        if ($action === CourseSequenceAction::Clear) {
            return $this->emptyCourseWarning($eintraege);
        }

        // Trägt die Version schon Kurse mit anderer Nullpolsterung, entstünden zwei Kurse, die
        // für den Menschen derselbe sind — `03` und `3` sind für die Datenbank verschieden.
        $bestand = array_values(array_unique(array_column($vorhandene, 'number')));
        $geplant = array_values(array_unique(array_column($eintraege, 'number')));

        foreach ($geplant as $nummer) {
            foreach ($bestand as $vorhanden) {
                if ($nummer !== $vorhanden && ltrim($nummer, '0') === ltrim($vorhanden, '0') && ltrim($nummer, '0') !== '') {
                    return [[
                        'code' => 'number_style_mismatch',
                        'message' => sprintf(
                            'Diese Linie trägt bereits Kurs „%s". Die Folge liefert „%s" — das wären zwei verschiedene '
                            .'Umläufe, obwohl es für das Auge derselbe ist. Schreib die Nummern in derselben Form.',
                            $vorhanden,
                            $nummer,
                        ),
                    ]];
                }
            }
        }

        return [];
    }

    /**
     * Kurse, die nach dem Entfernen ohne jede Fahrt zurückbleiben.
     *
     * Gemeldet, nicht gelöscht: {@see CourseService::detach()} lässt den Umlauf bewusst bestehen,
     * seine Nummer ist nicht verbraucht. Eine leere Hülle hängt aber an keiner Linie und
     * kollidiert deshalb in beiden Übersichten mit jedem gleichnamigen Kurs — das soll auffallen.
     *
     * @param  array<int, array<string, mixed>>  $eintraege
     * @return array<int, array<string, mixed>>
     */
    private function emptyCourseWarning(array $eintraege): array
    {
        $betroffen = [];

        foreach ($eintraege as $eintrag) {
            $betroffen = [...$betroffen, ...$eintrag['chain_trip_ids']];
        }

        $kurse = DB::table('course_trips')
            ->whereIn('consolidated_trip_id', $betroffen)
            ->distinct()
            ->pluck('course_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($kurse === []) {
            return [];
        }

        $leer = [];

        foreach ($kurse as $kursId) {
            $verbleibend = DB::table('course_trips')
                ->where('course_id', $kursId)
                ->whereNotIn('consolidated_trip_id', $betroffen)
                ->count();

            if ($verbleibend === 0) {
                $leer[] = Course::query()->whereKey($kursId)->value('number');
            }
        }

        $leer = array_values(array_filter($leer));

        if ($leer === []) {
            return [];
        }

        sort($leer, SORT_NATURAL);

        return [[
            'code' => 'course_empty_after',
            'message' => sprintf(
                '%d %s bleiben danach ohne Fahrten (%s). Die Nummern sind wieder frei; die leeren Umläufe melden '
                .'sich bis dahin als Dublette und lassen sich unter „Kurse" löschen.',
                count($leer),
                count($leer) === 1 ? 'Umlauf' : 'Umläufe',
                implode(', ', $leer),
            ),
        ]];
    }

    /**
     * @param  array<string, mixed>  $fahrt
     * @return array<string, mixed>
     */
    private function skip(int $spalte, ?string $nummer, array $fahrt, string $code, string $meldung): array
    {
        return [
            'column' => $spalte,
            'number' => $nummer,
            'trip' => $fahrt,
            'reason_code' => $code,
            'reason' => $meldung,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $richtung
     * @param  array<int, int>|null  $bereich
     * @param  array<string, mixed>|null  $muster
     * @param  array<int, array<string, mixed>>  $eintraege
     * @param  array<int, array<string, mixed>>  $unveraendert
     * @param  array<int, array<string, mixed>>  $uebersprungen
     * @param  array<int, array<string, mixed>>  $konflikte
     * @param  array<int, array<string, mixed>>  $hinweise
     * @return array<string, mixed>
     */
    private function antwort(
        LineVersion $version,
        ?array $richtung,
        ?array $bereich,
        ?array $muster,
        array $eintraege,
        array $unveraendert,
        array $uebersprungen,
        array $konflikte,
        array $hinweise,
        CourseSequenceAction $action,
        bool $anwenden,
    ): array {
        $istSetzen = $action === CourseSequenceAction::Assign;
        $spalten = $bereich === null ? [] : array_keys($bereich);

        $betroffen = (int) array_sum(array_column($eintraege, 'chain_trip_count'));
        $ausserhalb = (int) array_sum(array_map(
            static fn (array $e): int => count($e['outside_range']),
            $eintraege,
        ));

        return [
            'action' => $action->value,
            'line_version' => [
                'id' => $version->id,
                'line' => $version->line,
                'day_type' => $version->day_type->value,
                'day_type_label' => $version->day_type->label(),
                'version_no' => $version->version_no,
            ],
            'direction' => $richtung === null ? null : [
                'key' => $richtung['key'],
                'start_stop' => $richtung['start_stop'],
                'end_stop' => $richtung['end_stop'],
                'trip_count' => $richtung['trip_count'],
            ],
            'pattern' => $muster,
            'range' => $bereich === null ? null : [
                'from_trip_id' => $bereich[$spalten[0]],
                'to_trip_id' => $bereich[$spalten[count($spalten) - 1]],
                'from_index' => $spalten[0],
                'to_index' => $spalten[count($spalten) - 1],
                'columns' => count($spalten),
            ],
            'summary' => [
                'columns' => count($spalten),
                'planned' => count($eintraege),
                // Bewusst die Zahl der **Fahrten**, nicht der Spalten: Die Kette zieht mit, und
                // ein Bedienknopf, der die kleinere Zahl trägt, kündigt zu wenig an.
                'trips_affected' => $betroffen,
                'outside_range' => $ausserhalb,
                'unchanged' => count($unveraendert),
                'skipped' => count($uebersprungen),
                'conflicts' => count($konflikte),
                'written' => $anwenden && $istSetzen ? count($eintraege) : 0,
                'removed' => $anwenden && ! $istSetzen ? count($eintraege) : 0,
                'applied' => $anwenden,
            ],
            'assignments' => $istSetzen ? $eintraege : [],
            'removals' => $istSetzen ? [] : $eintraege,
            'unchanged' => $unveraendert,
            'skipped' => $uebersprungen,
            'conflicts' => $konflikte,
            'warnings' => $hinweise,
        ];
    }
}
