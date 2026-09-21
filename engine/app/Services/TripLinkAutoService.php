<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AutoLinkAction;
use App\Enums\TripLinkKind;
use App\Enums\TripLinkRejection;
use App\Models\ConsolidatedTrip;
use App\Models\TripLink;
use App\Support\AutoLinkScope;
use App\Support\TripChainGraph;
use App\Support\TripLinkRejectionReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Schreibt das Anschluss-Muster einer Endstelle über einen Zeitraum fort — und nimmt es wieder
 * zurück.
 *
 * An einer Haltestelle mit Takt ist das Muster offensichtlich: Die Bahn kommt an und übernimmt
 * die nächste Abfahrt. Über einen Morgen hinweg wiederholt sich das dutzendfach identisch. Jeden
 * Übergang einzeln zu klicken ist Fleißarbeit ohne eigene Aussage.
 *
 * **Der Mensch setzt die Grenzen, nicht die Automatik.** Das Muster bricht irgendwann — ab einer
 * bestimmten Uhrzeit wird der Linienwechsel eben doch gefahren. Deshalb markiert der Pflegende
 * Start- und Endfahrt, und deshalb entscheidet sein Filter mit: Ein Wechsel 1 → 13 ist manchmal
 * Absicht und manchmal nicht, und nur er weiß, welcher Fall vorliegt.
 *
 * **Die Paarung ist FIFO.** Jede Ankunft bekommt die früheste noch freie Abfahrt im Zeitfenster.
 * Die Höchstwende ist dabei kein Schönheitswert, sondern der Abbruch: Ohne sie griffe der Lauf in
 * einer Taktlücke nach einer Abfahrt zwei Stunden später und behauptete einen Umlauf, den es
 * nicht gibt.
 *
 * Aufbau wie {@see CourseCarryoverService}: Vorschau und Anwenden sind derselbe Lauf mit einem
 * Schalter, und die Vorschau ist garantiert folgenlos.
 */
final class TripLinkAutoService
{
    public function __construct(
        private readonly StopLinkBoardService $board,
        private readonly TripLinkRuleService $rules,
        private readonly TripLinkService $links,
        private readonly CourseService $courses,
    ) {}

    /**
     * Was der Lauf bewirken würde — ohne etwas zu ändern.
     *
     * @return array<string, mixed>
     */
    public function preview(AutoLinkScope $scope): array
    {
        return $this->run($scope, anwenden: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(AutoLinkScope $scope): array
    {
        $ergebnis = DB::transaction(fn (): array => $this->run($scope, anwenden: true));

        Log::info('Auto trip links applied', [
            'stop_group_id' => $scope->stopGroup->id,
            'period_id' => $scope->period->id,
            'day_type' => $scope->dayType->value,
            'action' => $scope->action->value,
            'lines' => $scope->lines,
        ] + $ergebnis['summary']);

        return $ergebnis;
    }

    /**
     * @return array<string, mixed>
     */
    private function run(AutoLinkScope $scope, bool $anwenden): array
    {
        // Genau **einmal**: Der Board-Aufbau klassifiziert jeden Tag des Zeitraums einzeln, um
        // die Versionsstände zu falten. Ihn je Schritt zu rufen wäre die teuerste Zeile hier.
        $board = $this->board->board($scope->stopGroup, $scope->period, $scope->dayType, $scope->standIndex);

        $endend = array_values(array_filter($board['ending'], $scope->matches(...)));
        $beginnend = array_values(array_filter($board['starting'], $scope->matches(...)));

        // Entlang des Betriebstags, mit der Fahrt-Id als Stichentscheid — dieselbe Regel wie in
        // der Anzeige. Ohne den Stichentscheid hinge die Bereichsgrenze bei zeitgleichen
        // Ankünften an der Reihenfolge, in der die Datenbank die Zeilen liefert.
        usort($endend, static fn (array $a, array $b): int => $a['arrival_sort'] <=> $b['arrival_sort'] ?: $a['id'] <=> $b['id']);
        usort($beginnend, static fn (array $a, array $b): int => $a['departure_sort'] <=> $b['departure_sort'] ?: $a['id'] <=> $b['id']);

        $bereich = $this->resolveRange($endend, $scope);

        if ($bereich === null) {
            return $this->antwort($scope, $board, null, [], [], [], $anwenden);
        }

        if ($scope->action === AutoLinkAction::Unlink) {
            [$zeilen, $uebersprungen] = $this->planUnlink($bereich, $beginnend, $scope);

            if ($anwenden) {
                $zeilen = $this->writeRemovals($zeilen);
            }

            return $this->antwort($scope, $board, $bereich, [], $zeilen, $uebersprungen, $anwenden);
        }

        [$paare, $uebersprungen] = $this->planLinks($bereich, $endend, $beginnend, $scope);

        if ($anwenden) {
            [$paare, $weitere] = $this->writeLinks($paare);
            $uebersprungen = [...$uebersprungen, ...$weitere];
        }

        return $this->antwort($scope, $board, $bereich, $paare, [], $uebersprungen, $anwenden);
    }

    /**
     * Die markierten Fahrten und alles dazwischen — **inklusive** beider Grenzen.
     *
     * `null`, wenn eine Markierung nicht in der gefilterten Liste liegt. Das ist ein Fehler und
     * kein leeres Ergebnis: Der Lauf bearbeitete sonst einen Ausschnitt, den der Pflegende gar
     * nicht vor sich hat.
     *
     * @param  array<int, array<string, mixed>>  $endend
     * @return array<int, array<string, mixed>>|null
     */
    private function resolveRange(array $endend, AutoLinkScope $scope): ?array
    {
        $von = null;
        $nach = null;

        foreach ($endend as $i => $fahrt) {
            if ($fahrt['id'] === $scope->fromTripId) {
                $von = $i;
            }
            if ($fahrt['id'] === $scope->toTripId) {
                $nach = $i;
            }
        }

        if ($von === null || $nach === null) {
            return null;
        }

        // Verkehrt herum markiert ist kein Fehler, sondern eine Handbewegung von unten nach oben.
        if ($von > $nach) {
            [$von, $nach] = [$nach, $von];
        }

        return array_slice($endend, $von, $nach - $von + 1);
    }

    /**
     * Die FIFO-Paarung.
     *
     * **Was geschieht, wenn ein Kandidat abgewiesen wird:** Der nächste rückt nach, und der erste
     * Ablehnungsgrund wird gemerkt. Trägt später einer, wird gepaart und nichts gemeldet — die
     * Ablehnung war dann bloß ein Zwischenschritt. Trägt keiner, erscheint der gemerkte Grund.
     * Die Alternative — beim ersten Nein aufgeben — ließe an einer Haltestelle mit Tram und Bus
     * die halbe Spalte liegen, weil dort der Gattungswechsel ständig dazwischenkommt.
     *
     * @param  array<int, array<string, mixed>>  $bereich
     * @param  array<int, array<string, mixed>>  $endend
     * @param  array<int, array<string, mixed>>  $beginnend
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function planLinks(array $bereich, array $endend, array $beginnend, AutoLinkScope $scope): array
    {
        $modelle = $this->tripModels([
            ...array_column($endend, 'id'),
            ...array_column($beginnend, 'id'),
        ]);

        $graph = $this->links->graphFor(array_keys($modelle));
        $this->rules->warmStopGroups($this->stopIdsOf($modelle));

        $verplant = [];
        $paare = [];
        $uebersprungen = [];

        foreach ($bereich as $fahrt) {
            if ($fahrt['decision'] !== null) {
                $uebersprungen[] = $this->skip('ending', $fahrt, null, 'trip_decided', sprintf(
                    'Die Fahrt trägt bereits eine Entscheidung (%s).',
                    TripLinkKind::from($fahrt['decision']['kind'])->label(),
                ));

                continue;
            }

            [$partner, $grund, $geprueft, $rueckwaerts] = $this->pickPartner($fahrt, $beginnend, $verplant, $modelle, $graph, $scope);

            if ($partner === null) {
                $uebersprungen[] = match (true) {
                    $grund !== null => $this->skip('ending', $fahrt, $geprueft, $grund->code->value, $grund->message),
                    $rueckwaerts !== null => $this->skip(
                        'ending',
                        $fahrt,
                        $rueckwaerts,
                        TripLinkRejection::NegativeTurnaround->value,
                        'Alle Abfahrten hier liegen auf dem Betriebstag **vor** dieser Ankunft. '
                        .'Beim Übergang vom Nacht- auf das Tagnetz ist das der Regelfall: Beide Linien tragen '
                        .'verschiedene Betriebstag-Grenzen, und eine Nachtfahrt am frühen Morgen gehört noch '
                        .'zum Vortag.',
                    ),
                    default => $this->skip('ending', $fahrt, null, 'no_partner', sprintf(
                        'Innerhalb von %d Minuten beginnt hier keine freie Fahrt.',
                        intdiv($scope->maxTurnaroundSeconds, 60),
                    )),
                };

                continue;
            }

            $verplant[$partner['id']] = true;
            $graph->link($fahrt['id'], $partner['id']);

            $wende = $partner['departure_sort'] - $fahrt['arrival_sort'];

            $paare[] = [
                'from_trip' => $fahrt,
                'to_trip' => $partner,
                'turnaround_seconds' => $wende,
                'warnings' => $this->warnings($fahrt, $partner, $wende),
                'course' => null,
                'course_trips_assigned' => 0,
                'course_conflict' => false,
            ];
        }

        return [$paare, [...$uebersprungen, ...$this->occupiedDepartures($bereich, $beginnend, $scope)]];
    }

    /**
     * Die früheste freie Abfahrt im Zeitfenster, die die Zulässigkeitsprüfung besteht.
     *
     * @param  array<string, mixed>  $fahrt
     * @param  array<int, array<string, mixed>>  $beginnend
     * @param  array<int, bool>  $verplant
     * @param  array<int, ConsolidatedTrip>  $modelle
     * @return array{0: array<string, mixed>|null, 1: TripLinkRejectionReason|null, 2: array<string, mixed>|null, 3: array<string, mixed>|null}
     *                                                                                                                                          Partner, Ablehnungsgrund, der dabei geprüfte Kandidat, und — falls es
     *                                                                                                                                          gar keinen vorwärts gelegenen gab — die erste rückwärts liegende Abfahrt.
     */
    private function pickPartner(
        array $fahrt,
        array $beginnend,
        array $verplant,
        array $modelle,
        TripChainGraph $graph,
        AutoLinkScope $scope,
    ): array {
        // Eine unlesbare Ankunft trägt keine Wendezeit; daraus eine zu rechnen wäre erfunden.
        if ($fahrt['arrival_sort'] === PHP_INT_MAX) {
            return [null, null, null, null];
        }

        $grund = null;
        $geprueft = null;
        $rueckwaerts = null;

        foreach ($beginnend as $kandidat) {
            if ($kandidat['decision'] !== null || isset($verplant[$kandidat['id']])) {
                continue;
            }

            // Eine Fahrt, die hier endet **und** beginnt (Wendeschleife), kann nicht an sich
            // selbst anschließen. Ohne diesen Ausstieg verbrauchte sie einen Ablehnungsplatz
            // und verdeckte den echten Grund.
            if ($kandidat['id'] === $fahrt['id'] || $kandidat['departure_sort'] === PHP_INT_MAX) {
                continue;
            }

            $wende = $kandidat['departure_sort'] - $fahrt['arrival_sort'];

            if ($wende < $scope->minTurnaroundSeconds) {
                // Eine Abfahrt, die auf dem Betriebstag **vor** der Ankunft liegt, ist etwas
                // anderes als eine, die bloß zu knapp ist — und der Unterschied ist der
                // Nacht-auf-Tag-Übergang am Morgen: Beide Linien tragen verschiedene
                // Betriebstag-Grenzen, eine N1-Ankunft um 05:00 sortiert deshalb hinter eine
                // Abfahrt der Linie 1 um 05:20. „Keine Abfahrt in 20 Minuten" schickte den
                // Pflegenden hier einen Datenfehler suchen, den es nicht gibt.
                if ($wende < 0) {
                    $rueckwaerts ??= $kandidat;
                }

                continue;
            }

            // Die Liste ist nach Abfahrt sortiert — ab hier wird es nur noch später.
            if ($wende > $scope->maxTurnaroundSeconds) {
                break;
            }

            if (! isset($modelle[$fahrt['id']], $modelle[$kandidat['id']])) {
                continue;
            }

            $abgelehnt = $this->rules->rejectionFor($modelle[$fahrt['id']], $modelle[$kandidat['id']], $graph);

            if ($abgelehnt !== null) {
                $grund ??= $abgelehnt;
                $geprueft ??= $kandidat;

                continue;
            }

            return [$kandidat, null, null, null];
        }

        return [null, $grund, $geprueft, $rueckwaerts];
    }

    /**
     * Abfahrten, die im betrachteten Zeitraum liegen, aber bereits entschieden sind.
     *
     * Sie gehören ins Ergebnis, weil sie erklären, warum eine Ankunft leer ausging. Beschnitten
     * auf das Zeitfenster des Bereichs — eine belegte Abfahrt um 05:00 hat mit einem Lauf am
     * Abend nichts zu tun.
     *
     * @param  array<int, array<string, mixed>>  $bereich
     * @param  array<int, array<string, mixed>>  $beginnend
     * @return array<int, array<string, mixed>>
     */
    private function occupiedDepartures(array $bereich, array $beginnend, AutoLinkScope $scope): array
    {
        if ($bereich === []) {
            return [];
        }

        $frueheste = $bereich[0]['arrival_sort'] + $scope->minTurnaroundSeconds;
        $spaeteste = $bereich[count($bereich) - 1]['arrival_sort'] + $scope->maxTurnaroundSeconds;

        $ergebnis = [];

        foreach ($beginnend as $fahrt) {
            if ($fahrt['decision'] === null) {
                continue;
            }

            if ($fahrt['departure_sort'] < $frueheste || $fahrt['departure_sort'] > $spaeteste) {
                continue;
            }

            $ergebnis[] = $this->skip('starting', $fahrt, null, 'departure_decided', sprintf(
                'Diese Abfahrt trägt bereits eine Entscheidung (%s) und steht nicht zur Verfügung.',
                TripLinkKind::from($fahrt['decision']['kind'])->label(),
            ));
        }

        return $ergebnis;
    }

    /**
     * Welche Entscheidungen im Bereich gelöst werden.
     *
     * **Der Bereich ist der des Bildschirms:** Ein Anschluss wird in der Zeile seiner *endenden*
     * Fahrt angezeigt, also gehört er über deren Ankunft zum Bereich — auch wenn die zugehörige
     * Abfahrt außerhalb liegt. Gelöst wird, was man markiert sieht; `partner_outside_range` sagt
     * es vorher.
     *
     * @param  array<int, array<string, mixed>>  $bereich
     * @param  array<int, array<string, mixed>>  $beginnend
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function planUnlink(array $bereich, array $beginnend, AutoLinkScope $scope): array
    {
        $zeilen = [];
        $uebersprungen = [];
        $imBereich = array_flip(array_column($bereich, 'id'));

        foreach ($bereich as $fahrt) {
            $entscheidung = $fahrt['decision'];

            if ($entscheidung === null) {
                $uebersprungen[] = $this->skip('ending', $fahrt, null, 'not_decided', 'An dieser Fahrt hängt keine Entscheidung.');

                continue;
            }

            $art = TripLinkKind::from($entscheidung['kind']);

            if ($art !== TripLinkKind::Link && ! $scope->includeTerminals) {
                $uebersprungen[] = $this->skip('ending', $fahrt, null, 'terminal_decision', sprintf(
                    'Die Fahrt rückt ein (%s). Betriebsfahrten werden nur mit „auch Aus-/Einrücken lösen" entfernt.',
                    $art->label(),
                ));

                continue;
            }

            $partner = $entscheidung['partner'];

            $zeilen[] = [
                'trip_link_id' => $entscheidung['id'],
                'kind' => $art->value,
                'from_trip' => $fahrt,
                'to_trip' => $partner,
                'turnaround_seconds' => $entscheidung['turnaround_seconds'],
                'partner_outside_range' => $partner !== null && ! isset($imBereich[$partner['id']]),
            ];
        }

        return [
            [...$zeilen, ...$this->planUnlinkTerminals($bereich, $beginnend, $scope)],
            $uebersprungen,
        ];
    }

    /**
     * Ausrücken-Entscheidungen der **rechten** Spalte, nur mit gesetztem Schalter.
     *
     * Ohne sie bliebe „auch Aus-/Einrücken lösen" halbiert: Das Einrücken hängt links, das
     * Ausrücken rechts, und beides ist derselbe Betriebsfahrt-Fall. Der Zeitraum ist derselbe,
     * nur im Sortierschlüssel der eigenen Spalte ausgedrückt.
     *
     * @param  array<int, array<string, mixed>>  $bereich
     * @param  array<int, array<string, mixed>>  $beginnend
     * @return array<int, array<string, mixed>>
     */
    private function planUnlinkTerminals(array $bereich, array $beginnend, AutoLinkScope $scope): array
    {
        if (! $scope->includeTerminals || $bereich === []) {
            return [];
        }

        $von = $bereich[0]['arrival_sort'];
        $bis = $bereich[count($bereich) - 1]['arrival_sort'];

        $zeilen = [];

        foreach ($beginnend as $fahrt) {
            $entscheidung = $fahrt['decision'];

            if ($entscheidung === null || $entscheidung['kind'] !== TripLinkKind::Start->value) {
                continue;
            }

            if ($fahrt['departure_sort'] < $von || $fahrt['departure_sort'] > $bis) {
                continue;
            }

            $zeilen[] = [
                'trip_link_id' => $entscheidung['id'],
                'kind' => TripLinkKind::Start->value,
                'from_trip' => null,
                'to_trip' => $fahrt,
                'turnaround_seconds' => null,
                'partner_outside_range' => false,
            ];
        }

        return $zeilen;
    }

    /**
     * @param  array<int, array<string, mixed>>  $paare
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function writeLinks(array $paare): array
    {
        $geschrieben = [];
        $uebersprungen = [];

        foreach ($paare as $paar) {
            $von = ConsolidatedTrip::query()->find($paar['from_trip']['id']);
            $nach = ConsolidatedTrip::query()->find($paar['to_trip']['id']);

            if ($von === null || $nach === null) {
                continue;
            }

            // Zwischen Vorschau und Anwenden kann jemand anderes etwas gesetzt haben. Anders als
            // der Einzelklick, der darauf mit 409 antwortet, überspringt der Lauf die Zeile: Ein
            // Zwischenfall darf einen Lauf über 40 Übergänge nicht abbrechen.
            if ($this->alreadyDecided($von->id, $nach->id)) {
                $uebersprungen[] = $this->skip(
                    'ending',
                    $paar['from_trip'],
                    $paar['to_trip'],
                    'trip_decided',
                    'Seit der Vorschau wurde an einer der beiden Fahrten eine Entscheidung gesetzt.',
                );

                continue;
            }

            $this->links->create(TripLinkKind::Link, $von, $nach);

            // Zwei verknüpfte Fahrten sind dasselbe Fahrzeug, also derselbe Kurs (KURSE §2 K2).
            // Tragen beide Ketten verschiedene Nummern, wird nichts überschrieben — der
            // Widerspruch wird gemeldet, der Anschluss bleibt bestehen.
            $kurs = $this->courses->unifyChain($von);

            $paar['course'] = $kurs['course'] === null ? null : $this->courses->describe($kurs['course']);
            $paar['course_trips_assigned'] = $kurs['trips_assigned'];
            $paar['course_conflict'] = $kurs['conflict'];

            $geschrieben[] = $paar;
        }

        return [$geschrieben, $uebersprungen];
    }

    /**
     * @param  array<int, array<string, mixed>>  $zeilen
     * @return array<int, array<string, mixed>>
     */
    private function writeRemovals(array $zeilen): array
    {
        $geloest = [];

        foreach ($zeilen as $zeile) {
            $link = TripLink::query()->find($zeile['trip_link_id']);

            if ($link === null) {
                continue;
            }

            $this->links->remove($link);
            $geloest[] = $zeile;
        }

        return $geloest;
    }

    private function alreadyDecided(int $fromId, int $toId): bool
    {
        return DB::table('trip_links')
            ->where(function ($q) use ($fromId, $toId): void {
                $q->where('from_trip_id', $fromId)->orWhere('to_trip_id', $toId);
            })
            ->exists();
    }

    /**
     * Hinweise, die den Anschluss **nicht** verhindern — dieselben Codes wie beim Einzelklick.
     *
     * @param  array<string, mixed>  $von
     * @param  array<string, mixed>  $nach
     * @return array<int, array{code: string, message: string}>
     */
    private function warnings(array $von, array $nach, int $wendezeit): array
    {
        $hinweise = [];
        $schwelle = (int) config('mdtakt.courses.min_turnaround_minutes') * 60;

        if ($wendezeit >= 0 && $wendezeit < $schwelle) {
            $hinweise[] = [
                'code' => 'short_turnaround',
                'message' => sprintf('Die Wendezeit beträgt nur %d Minuten.', intdiv($wendezeit, 60)),
            ];
        }

        if ($von['line'] !== $nach['line']) {
            $hinweise[] = [
                'code' => 'line_change',
                'message' => sprintf('Die Kette wechselt von Linie %s auf Linie %s.', $von['line'], $nach['line']),
            ];
        }

        return $hinweise;
    }

    /**
     * @param  array<string, mixed>  $trip
     * @param  array<string, mixed>|null  $partner
     * @return array<string, mixed>
     */
    private function skip(string $seite, array $trip, ?array $partner, string $code, string $meldung): array
    {
        return [
            'side' => $seite,
            'trip' => $trip,
            'partner' => $partner,
            'reason_code' => $code,
            'reason' => $meldung,
        ];
    }

    /**
     * @param  array<int, int>  $tripIds
     * @return array<int, ConsolidatedTrip>
     */
    private function tripModels(array $tripIds): array
    {
        $eindeutig = array_values(array_unique($tripIds));

        if ($eindeutig === []) {
            return [];
        }

        return ConsolidatedTrip::query()
            ->with('lineVersion')
            ->whereIn('id', $eindeutig)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  array<int, ConsolidatedTrip>  $modelle
     * @return array<int, int>
     */
    private function stopIdsOf(array $modelle): array
    {
        $ids = [];

        foreach ($modelle as $fahrt) {
            $ids[] = $fahrt->first_stop_id;
            $ids[] = $fahrt->last_stop_id;
        }

        return array_values(array_filter($ids));
    }

    /**
     * @param  array<string, mixed>  $board
     * @param  array<int, array<string, mixed>>|null  $bereich
     * @param  array<int, array<string, mixed>>  $paare
     * @param  array<int, array<string, mixed>>  $zeilen
     * @param  array<int, array<string, mixed>>  $uebersprungen
     * @return array<string, mixed>
     */
    private function antwort(
        AutoLinkScope $scope,
        array $board,
        ?array $bereich,
        array $paare,
        array $zeilen,
        array $uebersprungen,
        bool $anwenden,
    ): array {
        $istLink = $scope->action === AutoLinkAction::Link;
        $geplant = $istLink ? count($paare) : count($zeilen);

        return [
            'action' => $scope->action->value,
            'stop_group' => ['id' => $board['stop_group']['id'], 'name' => $board['stop_group']['name']],
            'period' => $board['period'],
            'day_type' => $board['day_type'],
            'day_type_label' => $board['day_type_label'],
            'stand' => $board['stand'],
            'filter' => [
                'lines' => $scope->lines,
                'mode' => $scope->mode,
                'from_trip_id' => $scope->fromTripId,
                'to_trip_id' => $scope->toTripId,
                'min_turnaround_seconds' => $scope->minTurnaroundSeconds,
                'max_turnaround_seconds' => $scope->maxTurnaroundSeconds,
                'include_terminals' => $scope->includeTerminals,
            ],
            'defaults' => [
                'min_turnaround_seconds' => (int) config('mdtakt.courses.min_turnaround_minutes') * 60,
                'max_turnaround_seconds' => (int) config('mdtakt.courses.max_turnaround_minutes') * 60,
            ],
            'range' => $bereich === null ? null : [
                'from_trip' => $bereich[0],
                'to_trip' => $bereich[count($bereich) - 1],
                'trips' => count($bereich),
            ],
            'summary' => [
                'candidates' => $bereich === null ? 0 : count($bereich),
                'planned' => $geplant,
                'created' => $anwenden && $istLink ? $geplant : 0,
                'removed' => $anwenden && ! $istLink ? $geplant : 0,
                'skipped' => count($uebersprungen),
                'courses_unified' => (int) array_sum(array_column($paare, 'course_trips_assigned')),
                'course_conflicts' => count(array_filter($paare, static fn (array $p): bool => $p['course_conflict'])),
                'applied' => $anwenden,
            ],
            'pairs' => $paare,
            'removals' => $zeilen,
            'skipped' => $uebersprungen,
        ];
    }
}
