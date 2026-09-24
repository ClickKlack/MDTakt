<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use App\Models\SchedulePeriod;
use Illuminate\Support\Facades\Log;

/**
 * Die Umläufe einer Linie als **klassische Tabelle**: Halte als Zeilen, ein Kurs je Spalte.
 *
 * Die Kursübersicht zeigt je Umlauf eine Kette von Fahrten — gut, um einen einzelnen Umlauf zu
 * prüfen, unbrauchbar, um zwei zu vergleichen. Nebeneinander gestellt wird dagegen sichtbar, was
 * ein Takt ist: Kurs 1 und Kurs 2 fahren dieselbe Folge, nur versetzt, und ihre Spalten laufen
 * Zeile für Zeile parallel.
 *
 * **Die Zeilenachse folgt der Kette, nicht der Linie.** Ein Umlauf fährt hin und zurück; seine
 * Halte wiederholen sich deshalb, und die Achse bildet das ab (`A, B, C, C, B, A, A, B, C …`).
 * Gespiegelt wird nichts — jede Zeit läuft von oben nach unten, so wie das Fahrzeug fährt. Dass
 * dieselbe Haltestelle mehrfach untereinander steht, ist keine Doppelung, sondern die nächste
 * Runde.
 *
 * **Die Achse entsteht über {@see StopSequenceAligner}** — derselbe Dienst, der im Fahrplan
 * Kurzläufer und Umleitungen auf eine gemeinsame Achse bringt. Seine Zusicherung trägt auch
 * hier: Ein Fehlgriff der Heuristik äußert sich in überflüssigen Zeilen, **nie** in einer
 * Uhrzeit an der falschen Stelle.
 *
 * **Eine Tabelle je Laufweg.** Fährt eine Linie zwei getrennte Laufwege (die 1:
 * Kannenstieg–Listemannstraße und Sudenburg–City Carré), gibt es keinen gemeinsamen
 * Taktpunkt. Die Umläufe werden deshalb vorher nach gemeinsamen Endstellen gruppiert
 * ({@see self::partition()}), und jede Gruppe bekommt ihre eigene Achse.
 *
 * **In der Zelle steht die Abfahrt** — außer am letzten Halt einer Fahrt, dort die Ankunft. So
 * liest sich eine Spalte wie ein Fahrplan, und die Wende ist als Paar „Ankunft / Abfahrt
 * derselben Haltestelle" in zwei aufeinanderfolgenden Zeilen zu sehen.
 */
final class CourseGridService
{
    /**
     * Ab diesem Verhältnis von Achsenlänge zur längsten Einzelkette gilt die Ausrichtung als
     * fragwürdig — dieselbe Reißleine wie im Fahrplan. Sie ist eine Warnung, keine Erwartung.
     */
    private const ALIGNMENT_WARNING_RATIO = 1.5;

    public function __construct(
        private readonly CourseOverviewService $overview,
        private readonly StopSequenceAligner $aligner,
        private readonly OperatingDayResolver $operatingDay,
        private readonly CourseRoundLayout $rounds,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forLine(string $line, SchedulePeriod $period, FahrplanTyp $typ, ?int $standIndex = null): array
    {
        $uebersicht = $this->overview->forLine($line, $period, $typ, withStops: true, standIndex: $standIndex);

        $abschnitte = [];

        foreach ($this->partition($uebersicht['courses'], $line) as $gruppe) {
            $abschnitt = $this->section($gruppe['courses'], $gruppe['termini'], $line);

            if ($abschnitt !== null) {
                $abschnitte[] = $abschnitt;
            }
        }

        if (count($abschnitte) > 1) {
            Log::debug('Course grid split by route', [
                'line' => $line,
                'period_id' => $period->id,
                'day_type' => $typ->value,
                'sections' => array_map(static fn (array $a): array => [
                    'termini' => $a['termini'],
                    'courses' => array_column($a['courses'], 'number'),
                ], $abschnitte),
            ]);
        }

        return $this->antwort($uebersicht, $abschnitte);
    }

    /**
     * Eine Tabelle: gemeinsame Achse für eine Gruppe von Umläufen, die sich messen lassen.
     *
     * `null`, wenn keiner der Umläufe eine geladene Haltefolge trägt.
     *
     * **Zwei Wege zur Achse.** Gibt es eine Haltestelle, an der jeder Umlauf mehrfach und immer
     * in dieselbe Richtung abfährt, werden die Runden dort abgezählt — der bewährte Weg für
     * gewöhnliche Linien. Fehlt sie, entsteht die Runde aus dem Fahrtmuster
     * ({@see CourseRoundLayout}): in der Verknüpfung der 1, und solange Ketten Bruchstücke sind.
     *
     * @param  array<int, array<string, mixed>>  $kurse
     * @param  array<int, string>  $termini
     * @return array<string, mixed>|null
     */
    private function section(array $kurse, array $termini, string $line): ?array
    {
        // Umläufe ohne geladene Haltefolge fallen vorab weg — beide Wege zählen danach
        // dieselben Spalten.
        $kurse = array_values(array_filter(
            $kurse,
            static fn (array $k): bool => array_filter($k['trips'], static fn (array $f): bool => ($f['stops'] ?? []) !== []) !== [],
        ));

        [$varianten, $spalten, $namen] = $this->collect($kurse);

        if ($varianten === []) {
            return null;
        }

        // Die Haltestelle, an der sich die Umlaeufe messen lassen, und die Laenge einer Runde.
        $takt = $this->anchor($varianten, $spalten);

        // Eine einzelne Spalte hat niemanden, neben dem sie stehen müsste: Sie bleibt ihre
        // eigene Kette, ohne Gerüst.
        $muster = $takt === null && count($varianten) >= 2 ? $this->rounds->layout($kurse, $line) : null;

        [$ausrichtung, [$achse, $zuordnung, $warnung]] = match (true) {
            $takt !== null => ['stop', $this->alignByRound($varianten, $spalten, $takt)],
            $muster !== null => ['pattern', $muster],
            default => ['none', $this->alignWhole($varianten)],
        };

        foreach ($spalten as $i => &$spalte) {
            $spalte['cells'] = $this->place($spalte['cells'], $zuordnung[$i] ?? [], count($achse));
        }
        unset($spalte);

        return [
            'termini' => $termini,
            // Wie die Achse entstand — davon hängt ab, was eine Zeile quer gelesen bedeutet:
            // `stop` Takt an einer Haltestelle (aufsteigend), `pattern` feste Runden aus dem
            // Fahrtmuster (dieselbe Stelle, dieselbe Runde), `none` unverschoben.
            'alignment' => $ausrichtung,
            'rows' => $this->rows($achse, $namen),
            'courses' => $spalten,
            'alignment_warning' => $warnung,
        ];
    }

    /**
     * Zerlegt die Umläufe einer Linie nach **Laufweg** — eine Gruppe je Tabelle.
     *
     * Die Linie 1 ist zwei Linien unter einem Namen: Kannenstieg–Listemannstraße als reine 1,
     * und Sudenburg–City Carré mitten in der Verknüpfung mit 13, 2 und 5. Die beiden teilen
     * keine einzige Haltestelle. In einer Tabelle fand sich deshalb kein gemeinsamer Taktpunkt,
     * und beide Laufwege lagen unverschoben übereinander.
     *
     * **Verbunden sind zwei Umläufe, wenn ihre Fahrten der gewählten Linie eine Endstelle
     * teilen** — auch über Dritte hinweg. Endstellen statt aller Halte: Zwei getrennte
     * Laufwege dürfen sich in der Innenstadt kreuzen, ohne zusammenzufallen. Ein Kurzläufer
     * oder ein Ast teilt dagegen immer eine Endstelle mit dem Hauptweg und bleibt in derselben
     * Tabelle. Ein Umlauf, der beide Laufwege fährt, verbindet sie — dann ist es richtig eine.
     *
     * **Das Hof-Ende einer Ausrück- oder Einrückfahrt zählt nicht als Endstelle.** Rücken beide
     * Laufwege aus demselben Hof aus, verbände die Ausrückfahrt sonst, was im Takt nichts
     * miteinander zu tun hat. Erkannt wird das an der Marke der Fahrt (`terminal_out` /
     * `terminal_in`), nicht an der Haltestelle: Am Hof fahren auch Linien regulär vorbei.
     *
     * @param  array<int, array<string, mixed>>  $kurse
     * @return array<int, array{courses: array<int, array<string, mixed>>, termini: array<int, string>}>
     */
    private function partition(array $kurse, string $line): array
    {
        if ($kurse === []) {
            return [];
        }

        // Union-Find über die Umläufe: je Endstelle der erste Umlauf, der sie anfährt.
        $eltern = array_keys($kurse);
        $wurzel = static function (int $i) use (&$eltern): int {
            while ($eltern[$i] !== $i) {
                $eltern[$i] = $eltern[$eltern[$i]];
                $i = $eltern[$i];
            }

            return $i;
        };

        $erster = [];
        $haeufigkeit = [];
        $namen = [];

        foreach ($kurse as $i => $kurs) {
            $eigene = $this->termini($kurs, $line, $namen);

            foreach ($eigene as $stopId => $anzahl) {
                $haeufigkeit[$i][$stopId] = ($haeufigkeit[$i][$stopId] ?? 0) + $anzahl;

                if (! isset($erster[$stopId])) {
                    $erster[$stopId] = $i;

                    continue;
                }

                $a = $wurzel($i);
                $b = $wurzel($erster[$stopId]);

                if ($a !== $b) {
                    // Die kleinere Wurzel gewinnt — so bleibt die Gruppe beim frühesten Kurs.
                    $eltern[max($a, $b)] = min($a, $b);
                }
            }
        }

        $gruppen = [];

        // In Kursreihenfolge: Die Gruppe mit dem ersten Kurs kommt zuerst.
        foreach ($kurse as $i => $kurs) {
            $w = $wurzel($i);
            $gruppen[$w]['courses'][] = $kurs;

            foreach ($haeufigkeit[$i] ?? [] as $stopId => $anzahl) {
                $gruppen[$w]['counts'][$stopId] = ($gruppen[$w]['counts'][$stopId] ?? 0) + $anzahl;
            }
        }

        return array_values(array_map(static function (array $g) use ($namen): array {
            $zaehler = $g['counts'] ?? [];
            arsort($zaehler);

            return [
                'courses' => $g['courses'],
                'termini' => array_values(array_map(
                    static fn (int $stopId): string => $namen[$stopId] ?? '—',
                    array_keys($zaehler),
                )),
            ];
        }, $gruppen));
    }

    /**
     * Die Endstellen eines Umlaufs auf der gewählten Linie, mit ihrer Häufigkeit.
     *
     * Nur Fahrten der gewählten Linie: In der Verknüpfung fährt derselbe Umlauf auch als 13, 2
     * und 5 — deren Endstellen sagen nichts darüber, wo die 1 fährt. Hat ein Umlauf keine
     * einzige Fahrt dieser Linie (er erscheint über einen Anschluss), zählen ersatzweise alle.
     *
     * Ist der Umlauf als ausrückend markiert, fällt der erste Halt seiner ersten Fahrt weg —
     * dort kommt das Fahrzeug aus dem Hof, nicht von einer Endstelle. Ebenso der letzte Halt der
     * letzten Fahrt bei einer Einrück-Marke. Das andere Ende dieser Fahrten zählt weiter —
     * auf der 2 ist Westerhüsen Hof und reguläre Endstelle zugleich und bleibt über die übrigen
     * Fahrten im Spiel.
     *
     * Bleibt danach nichts übrig, zählen die Hof-Enden doch: Ein Verstärker aus einer einzigen
     * Fahrt, als Aus- und Einrücken markiert, stünde sonst ohne Endstelle da und bekäme eine
     * eigene Tabelle mit einer Spalte.
     *
     * @param  array<string, mixed>  $kurs
     * @param  array<int, string>  $namen  wird um die Namen der gefundenen Endstellen ergänzt
     * @param  bool  $mitHof  Marken übergehen — der Rückfallweg, wenn ohne Hof-Enden nichts bleibt
     * @return array<int, int> stop_id => Anzahl
     */
    private function termini(array $kurs, string $line, array &$namen, bool $mitHof = false): array
    {
        $fahrten = $kurs['trips'];
        $letzte = count($fahrten) - 1;
        $aus = ! $mitHof && (bool) ($kurs['terminal_out']['marked'] ?? false);
        $ein = ! $mitHof && (bool) ($kurs['terminal_in']['marked'] ?? false);

        $nurLinie = array_filter($fahrten, static fn (array $f): bool => $f['line'] === $line);
        $gezaehlt = $nurLinie === [] ? $fahrten : $nurLinie;

        $ergebnis = [];

        // Die Schlüssel bleiben die Positionen im ganzen Umlauf — nur so ist die erste und
        // letzte Fahrt auch dann erkennbar, wenn sie auf einer anderen Linie fährt.
        foreach ($gezaehlt as $position => $fahrt) {
            $halte = $fahrt['stops'] ?? [];

            if ($halte === []) {
                continue;
            }

            $enden = [];

            if (! ($aus && $position === 0)) {
                $enden[] = $halte[0];
            }

            if (! ($ein && $position === $letzte)) {
                $enden[] = $halte[count($halte) - 1];
            }

            foreach ($enden as $halt) {
                $stopId = (int) $halt['stop_id'];
                $ergebnis[$stopId] = ($ergebnis[$stopId] ?? 0) + 1;
                $namen[$stopId] = $halt['stop_name'];
            }
        }

        if ($ergebnis === [] && ! $mitHof && ($aus || $ein)) {
            return $this->termini($kurs, $line, $namen, mitHof: true);
        }

        return $ergebnis;
    }

    /**
     * Zerlegt jeden Umlauf in seine Haltefolge und die zugehörigen Zellen.
     *
     * Beides entsteht in einem Durchgang und bleibt positionsgleich — die Ausrichtung bildet
     * später Position auf Zeile ab, und eine Verschiebung zwischen den beiden Listen wäre genau
     * der Fehler, den der Ausrichter selbst ausschließt.
     *
     * @param  array<int, array<string, mixed>>  $kurse
     * @return array{0: array<int, array{stops: array<int, int>, weight: int}>, 1: array<int, array<string, mixed>>, 2: array<int, string>}
     */
    private function collect(array $kurse): array
    {
        $varianten = [];
        $spalten = [];
        $namen = [];

        foreach ($kurse as $kurs) {
            $halte = [];
            $zellen = [];

            foreach ($kurs['trips'] as $fahrt) {
                $folge = $fahrt['stops'] ?? [];
                $letzte = count($folge) - 1;

                foreach ($folge as $i => $halt) {
                    $halte[] = $halt['stop_id'];
                    $namen[$halt['stop_id']] = $halt['stop_name'];

                    // Abfahrt, außer am letzten Halt der Fahrt — dort endet sie, und eine
                    // Abfahrt zu nennen wäre erfunden.
                    $zellen[] = [
                        'time' => $i === $letzte
                            ? ($halt['arrival_time'] ?? $halt['departure_time'])
                            : ($halt['departure_time'] ?? $halt['arrival_time']),
                        'kind' => $i === $letzte ? 'arrival' : 'departure',
                        // Die Endstelle trägt zwei Zeiten in zwei Zeilen: die Ankunft, mit der
                        // eine Fahrt endet, und die Abfahrt, mit der die nächste beginnt. Nur
                        // `kind` unterschiede sie nicht — eine Abfahrt am Zwischenhalt sieht
                        // genauso aus. Erst mit diesem Feld ist die Wende als Paar erkennbar.
                        'starts_trip' => $i === 0,
                        'line' => $fahrt['line'],
                    ];
                }
            }

            // Ein Umlauf ohne geladene Haltefolge trüge eine leere Spalte bei und verschöbe
            // die Zuordnung der übrigen.
            if ($halte === []) {
                continue;
            }

            $varianten[] = ['stops' => $halte, 'weight' => count($kurs['trips'])];

            $spalten[] = [
                'id' => $kurs['id'],
                'number' => $kurs['number'],
                'lines' => $kurs['lines'],
                'trip_count' => $kurs['trip_count'],
                'breaks' => $kurs['breaks'],
                'duplicate' => $kurs['duplicate'],
                'first_departure' => $kurs['first_departure'],
                'last_arrival' => $kurs['last_arrival'],
                'cells' => $zellen,
            ];
        }

        return [$varianten, $spalten, $namen];
    }

    /**
     * Legt die Zellen eines Umlaufs auf die gemeinsame Achse.
     *
     * @param  array<int, array<string, mixed>|null>  $zellen
     * @param  array<int, int>  $zuordnung  Position in der Kette => Zeilenindex
     * @return array<int, array<string, mixed>|null>
     */
    private function place(array $zellen, array $zuordnung, int $zeilenzahl): array
    {
        $ergebnis = array_fill(0, $zeilenzahl, null);

        foreach ($zellen as $position => $zelle) {
            if ($zelle !== null && isset($zuordnung[$position])) {
                $ergebnis[$zuordnung[$position]] = $zelle;
            }
        }

        return $ergebnis;
    }

    /**
     * Die Haltestelle, an der sich die Umläufe messen lassen.
     *
     * Gesucht ist ein Halt, den **jeder** Umlauf anfährt, und zwar möglichst oft — er ist der
     * Taktpunkt der Linie. An ihm werden die Runden abgezählt und die Spalten gegeneinander
     * gestellt. `null`, wenn es keinen gibt; dann bleibt es bei der alten, ungeschobenen Achse.
     *
     * @param  array<int, array{stops: array<int, int>, weight: int}>  $varianten
     * @param  array<int, array<string, mixed>>  $spalten
     * @return array{stop: int, splits: array<int, array<int, int>>}|null
     */
    private function anchor(array $varianten, array $spalten): ?array
    {
        if (count($varianten) < 2) {
            return null;
        }

        // Je Halt: wie oft ihn der sparsamste Umlauf anfährt. Ein Halt, den einer gar nicht
        // berührt, taugt nicht als gemeinsames Maß.
        $minimum = [];

        foreach ($varianten as $i => $variante) {
            $eigene = [];

            foreach ($variante['stops'] as $position => $stopId) {
                // Nur Abfahrten: Die Ankunft am selben Halt ist dasselbe Ereignis von der
                // anderen Seite und verdoppelte jede Runde.
                if (($spalten[$i]['cells'][$position]['kind'] ?? null) === 'departure') {
                    $eigene[$stopId] = ($eigene[$stopId] ?? 0) + 1;
                }
            }

            foreach ($i === 0 ? $eigene : $minimum as $stopId => $n) {
                $minimum[$stopId] = $i === 0 ? $n : min($minimum[$stopId], $eigene[$stopId] ?? 0);
            }
        }

        $minimum = array_filter($minimum, static fn (int $n): bool => $n >= 2);

        // Nur ein Halt, an dem jedes Fahrzeug **in dieselbe Richtung** abfährt: dieselbe Linie,
        // derselbe nächste Halt. Am City Carré fährt ein Umlauf der Verknüpfung je Runde als 1,
        // 13, 2 und 5 ab — dort abgezählt, stünde die 1 des einen Kurses neben der 13 des
        // anderen.
        $richtungen = [];

        foreach ($varianten as $i => $variante) {
            foreach ($variante['stops'] as $position => $stopId) {
                if (isset($minimum[$stopId]) && ($spalten[$i]['cells'][$position]['kind'] ?? null) === 'departure') {
                    $richtungen[$stopId][$spalten[$i]['cells'][$position]['line'].'>'.($variante['stops'][$position + 1] ?? '')] = true;
                }
            }
        }

        $minimum = array_filter(
            $minimum,
            static fn (int $n, int $stopId): bool => count($richtungen[$stopId] ?? []) === 1,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($minimum === []) {
            return null;
        }

        arsort($minimum);
        $anker = (int) array_key_first($minimum);

        // Die Positionen, an denen eine Runde beginnt — je Umlauf.
        $splits = [];

        foreach ($varianten as $i => $variante) {
            $splits[$i] = [];

            foreach ($variante['stops'] as $position => $stopId) {
                if ($stopId === $anker && ($spalten[$i]['cells'][$position]['kind'] ?? null) === 'departure') {
                    $splits[$i][] = $position;
                }
            }
        }

        return ['stop' => $anker, 'splits' => $splits];
    }

    /**
     * Die Achse ohne Verschiebung — der Rückfallweg, wenn sich kein gemeinsamer Taktpunkt findet.
     *
     * @param  array<int, array{stops: array<int, int>, weight: int}>  $varianten
     * @return array{0: array<int, int>, 1: array<int, array<int, int>>, 2: bool}
     */
    private function alignWhole(array $varianten): array
    {
        $ausgerichtet = $this->aligner->align($varianten);
        $laengste = max(array_map(static fn (array $v): int => count($v['stops']), $varianten));

        return [
            $ausgerichtet['axis'],
            $ausgerichtet['mapping'],
            $laengste > 0 && count($ausgerichtet['axis']) > $laengste * self::ALIGNMENT_WARNING_RATIO,
        ];
    }

    /**
     * Baut die Achse **blockweise**: ein Block je Umlauf-Runde.
     *
     * **Warum nicht als Ganzes.** Die Spalten sollen so stehen, dass eine Taktzeile quer gelesen
     * aufsteigt — Kurs 2 zeigt neben der ersten Runde von Kurs 1 also seine *zweite*. Verschiebt
     * man dafür eine fertig eingeordnete Spalte um n Zeilen, landen ihre Morgenfahrten auf den
     * falschen Halten: Die Achse wiederholt sich im Takt, aber nicht am Anfang, wo jedes Fahrzeug
     * anders ausrückt.
     *
     * **Deshalb je Runde ein Block.** In einem Block stehen die *gleichrangigen* Runden aller
     * Umläufe — und die haben denselben Laufweg, lassen sich also sauber übereinanderlegen. Der
     * Ausrück-Vorlauf jedes Fahrzeugs landet in dem Block, der seiner Rundenzählung entspricht,
     * und bekommt dort eigene Zeilen. Nichts geht verloren, nichts rutscht auf einen fremden Halt.
     *
     * @param  array<int, array{stops: array<int, int>, weight: int}>  $varianten
     * @param  array<int, array<string, mixed>>  $spalten
     * @param  array{stop: int, splits: array<int, array<int, int>>}  $takt
     * @return array{0: array<int, int>, 1: array<int, array<int, int>>, 2: bool}
     */
    private function alignByRound(array $varianten, array $spalten, array $takt): array
    {
        $versatz = $this->roundOffsets($spalten, $takt['splits']);

        // Jede Kette in ihre Abschnitte zerlegen: Vorlauf (Ausrücken) und dann je Runde einer.
        $bloecke = [];

        foreach ($varianten as $i => $variante) {
            $grenzen = [0, ...$takt['splits'][$i], count($variante['stops'])];
            $grenzen = array_values(array_unique($grenzen));

            for ($j = 0; $j < count($grenzen) - 1; $j++) {
                $von = $grenzen[$j];
                $bis = $grenzen[$j + 1];

                if ($bis <= $von) {
                    continue;
                }

                $bloecke[$j + $versatz[$i]][] = [
                    'course' => $i,
                    'from' => $von,
                    'stops' => array_slice($variante['stops'], $von, $bis - $von),
                ];
            }
        }

        ksort($bloecke);

        $achse = [];
        $zuordnung = array_fill_keys(array_keys($varianten), []);

        foreach ($bloecke as $abschnitte) {
            $lokal = $this->aligner->align(array_map(
                static fn (array $a): array => ['stops' => $a['stops'], 'weight' => 1],
                $abschnitte,
            ));

            $basis = count($achse);
            $achse = [...$achse, ...$lokal['axis']];

            foreach ($abschnitte as $k => $abschnitt) {
                foreach ($lokal['mapping'][$k] ?? [] as $position => $zeile) {
                    $zuordnung[$abschnitt['course']][$abschnitt['from'] + $position] = $basis + $zeile;
                }
            }
        }

        $laengste = max(array_map(static fn (array $v): int => count($v['stops']), $varianten));

        return [$achse, $zuordnung, $laengste > 0 && count($achse) > $laengste * self::ALIGNMENT_WARNING_RATIO * 2];
    }

    /**
     * Um wie viele Runden jede Spalte nach unten rutscht.
     *
     * Greedy in Spaltenreihenfolge: Jede Spalte nimmt ihre früheste Runde, die **nach** der
     * gewählten Runde der linken Nachbarin abfährt. Genau das meint „um n Umläufe verschoben" —
     * die Zeile liest sich danach als Folge aufeinanderfolgender Abfahrten, also als Takt.
     *
     * @param  array<int, array<string, mixed>>  $spalten
     * @param  array<int, array<int, int>>  $splits
     * @return array<int, int>
     */
    private function roundOffsets(array $spalten, array $splits): array
    {
        $gewaehlt = [];

        // **Gemessen wird erst, wenn alle Fahrzeuge draußen sind.** Vorher steht der Takt noch
        // nicht: Auf der 10 rückt Kurs 1 um 04:09 aus und Kurs 2 erst um 06:48. Setzte man den
        // Anker auf die erste Runde von Kurs 1, misst man ihn an einem Morgen, an dem die halbe
        // Flotte noch im Hof steht — die übrigen Spalten müssten dann alle über Stunden nach
        // unten ausweichen, und ausgerechnet der früheste Umlauf landete am tiefsten.
        $vorher = $this->serviceStart($spalten, $splits);

        foreach ($spalten as $i => $spalte) {
            $treffer = 0;

            foreach ($splits[$i] as $r => $position) {
                // Entlang des Betriebstags, nicht der Uhr: Auf der N1 fährt 22:49 vor 00:19.
                $zelle = $spalte['cells'][$position];
                $key = $this->operatingDay->sortKey($zelle['line'], $zelle['time']);

                if ($vorher === null || $key > $vorher) {
                    $treffer = $r;
                    $vorher = $key;
                    break;
                }
            }

            // Findet sich keine spätere Runde mehr, bleibt die Spalte bei ihrer ersten.
            $gewaehlt[$i] = $treffer;
        }

        $hoechste = max($gewaehlt);

        // Die Runde mit dem höchsten Index gibt den Takt vor; alle anderen rücken so weit nach
        // unten, dass ihre gewählte Runde daneben zu stehen kommt.
        return array_map(static fn (int $r): int => $hoechste - $r, $gewaehlt);
    }

    /**
     * Der Zeitpunkt, ab dem **jeder** Umlauf im Dienst ist — die späteste erste Runde.
     *
     * Eine Sekunde davor, damit die erste Spalte ihre Runde genau dort noch greifen kann. `null`,
     * wenn sich keine finden lässt; dann setzt die Messung wie bisher an der ersten Runde an.
     *
     * @param  array<int, array<string, mixed>>  $spalten
     * @param  array<int, array<int, int>>  $splits
     */
    private function serviceStart(array $spalten, array $splits): ?int
    {
        $spaeteste = null;

        foreach ($spalten as $i => $spalte) {
            $erste = $splits[$i][0] ?? null;

            if ($erste === null) {
                continue;
            }

            $zelle = $spalte['cells'][$erste];
            $key = $this->operatingDay->sortKey($zelle['line'], $zelle['time']);

            $spaeteste = $spaeteste === null ? $key : max($spaeteste, $key);
        }

        return $spaeteste === null ? null : $spaeteste - 1;
    }

    /**
     * @param  array<int, int>  $achse
     * @param  array<int, string>  $namen
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $achse, array $namen): array
    {
        $gesehen = [];
        $zeilen = [];

        foreach ($achse as $position => $stopId) {
            // Derselbe Halt kommt in einem Umlauf viele Male vor — jede Berührung ist eine
            // eigene Zeile. `repeat_index` macht das kenntlich, statt eine Doppelung vorzutäuschen.
            $gesehen[$stopId] = ($gesehen[$stopId] ?? -1) + 1;

            $zeilen[] = [
                'position' => $position,
                'stop_id' => $stopId,
                'stop_name' => $namen[$stopId] ?? '—',
                'repeat_index' => $gesehen[$stopId],
            ];
        }

        return $zeilen;
    }

    /**
     * @param  array<string, mixed>  $uebersicht
     * @param  array<int, array<string, mixed>>  $abschnitte
     * @return array<string, mixed>
     */
    private function antwort(array $uebersicht, array $abschnitte): array
    {
        return [
            'line' => $uebersicht['line'],
            'period' => $uebersicht['period'],
            'day_type' => $uebersicht['day_type'],
            'day_type_label' => $uebersicht['day_type_label'],
            // Derselbe Versionsstand wie in der Kettenansicht: Der Umschalter soll die
            // Darstellung wechseln, nicht den Ausschnitt.
            'stands' => $uebersicht['stands'],
            'stand' => $uebersicht['stand'],
            // Eine Tabelle je Laufweg — bei fast jeder Linie genau eine.
            'sections' => $abschnitte,
            // Dieselben Kennzahlen und dieselbe Liste wie die Kettenansicht: Der Umschalter
            // soll den Pflegestand nicht verändern, nur die Darstellung.
            'unassigned' => $uebersicht['unassigned'],
            'summary' => $uebersicht['summary'],
        ];
    }
}
