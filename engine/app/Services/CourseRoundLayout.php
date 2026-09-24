<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Die Zeilenachse einer Kurstabelle **aus dem Fahrtmuster** statt aus einer Haltestelle.
 *
 * **Wofür.** Die gewöhnliche Ausrichtung zählt Runden an einer Haltestelle ab, die jeder Umlauf
 * mehrfach anfährt ({@see CourseGridService}). Das trägt, solange ein Fahrzeug dort immer in
 * dieselbe Richtung abfährt. In der Verknüpfung 5 → 1 → 13 → 2 → 2 → 13 → 1 → 5 fährt es am
 * City Carré aber je Runde viermal ab, jedes Mal auf einer anderen Linie. Wer dort zählt,
 * stellt die 1 des einen Kurses neben die 13 des anderen. Und solange die Ketten nur
 * Bruchstücke sind, findet sich gar kein gemeinsamer Punkt.
 *
 * **Das Muster.** Jede Fahrt hat ein Muster: Linie, erster und letzter Halt. Aus den Ketten
 * ergibt sich, welches Muster auf welches folgt und mit welchem Abstand. Daraus entsteht die
 * **Runde**: die Muster in Fahrtfolge, jedes mit seinem Versatz zum Rundenbeginn. Die Runde
 * beginnt mit dem häufigsten Muster der gewählten Linie.
 *
 * **Plätze.** Jedes Muster hat seinen Platz in der Runde. Überschneidet sich ein Muster in der
 * Zeit mit einem häufigeren, ist es dessen Variante und teilt den Platz — die 1 über den Hbf
 * steht dort, wo sonst die 1 ab City Carré fährt.
 *
 * **Einordnen über die Uhrzeit.** Jede Fahrt bekommt einen *Rundenbeginn*: ihre Abfahrt minus
 * den Versatz ihres Musters. Fahrten eines Umlaufs mit demselben Rundenbeginn gehören in
 * dieselbe Runde. So landen auch Bruchstücke richtig — eine Kette, die mitten im Ring beginnt
 * oder eine Lücke hat, wird nicht abgezählt, sondern verortet.
 *
 * **Die Zeilen.** Je Runde des Tages ein Block, darin Platz für Platz: das Gerüst des Platzes,
 * und was dort fährt, eingepasst. Das Gerüst steht auch, wo niemand fährt — es ist die
 * Grundlinie. Die Zusicherung des {@see StopSequenceAligner} gilt unverändert: Ein Fehlgriff
 * kostet Zeilen, nie eine Uhrzeit am falschen Halt.
 */
final class CourseRoundLayout
{
    /** Längste Wende, die noch als Anschluss zwischen zwei Mustern gilt. */
    private const MAX_LAYOVER_SECONDS = 3600;

    /** Zwei Muster, deren Fahrzeiten sich um weniger überschneiden, gelten als nacheinander. */
    private const OVERLAP_TOLERANCE_SECONDS = 120;

    /** Das Gerüst geht jeder echten Fahrt vor — es prägt die Reihenfolge der Zeilen. */
    private const SKELETON_WEIGHT = 1_000_000;

    private const ALIGNMENT_WARNING_RATIO = 1.5;

    public function __construct(private readonly StopSequenceAligner $aligner) {}

    /**
     * @param  array<int, array<string, mixed>>  $kurse  in Spaltenreihenfolge, jeder mit geladenen Halten
     * @return array{0: array<int, int>, 1: array<int, array<int, int>>, 2: bool}|null
     *                                                                                 Achse, Zuordnung je Spalte, Warnung —
     *                                                                                 `null`, wenn sich keine Runde bilden lässt
     */
    public function layout(array $kurse, string $line): ?array
    {
        $fahrten = $this->trips($kurse);
        $muster = $this->patterns($fahrten);

        if ($muster['count'] === []) {
            return null;
        }

        $anker = $this->anchorPattern($muster, $line);
        [$versatz, $runde] = $this->offsets($muster, $anker);

        if ($runde === null) {
            return null;
        }

        [$plaetze, $platzVon] = $this->slots($muster, $versatz, $runde);
        [$runden, $beginn] = $this->assignRounds($fahrten, $versatz, $platzVon, count($plaetze), $runde);

        return $this->build($fahrten, $runden, $beginn, $runde, $plaetze, $muster);
    }

    /**
     * Die Fahrten je Umlauf, mit Muster, Zeiten und ihrer Position in der Zellenfolge.
     *
     * Die Position zählt genauso wie {@see CourseGridService::collect()}: Halt für Halt, Fahrt
     * für Fahrt. Nur so trifft die Zuordnung am Ende die richtige Zelle.
     *
     * @param  array<int, array<string, mixed>>  $kurse
     * @return array<int, array<int, array{pattern: string, dep: int|null, arr: int|null, from: int, stops: array<int, int>}>>
     */
    private function trips(array $kurse): array
    {
        $ergebnis = [];

        foreach ($kurse as $i => $kurs) {
            $position = 0;
            $ergebnis[$i] = [];

            foreach ($kurs['trips'] as $fahrt) {
                $halte = array_map(static fn (array $h): int => (int) $h['stop_id'], $fahrt['stops'] ?? []);

                if ($halte === []) {
                    continue;
                }

                $ergebnis[$i][] = [
                    'pattern' => $fahrt['line'].'|'.$halte[0].'|'.$halte[count($halte) - 1],
                    'dep' => $this->known($fahrt['departure_sort'] ?? null),
                    'arr' => $this->known($fahrt['arrival_sort'] ?? null),
                    'from' => $position,
                    'stops' => $halte,
                ];

                $position += count($halte);
            }
        }

        return $ergebnis;
    }

    /**
     * Häufigkeit, Dauer und Folgebeziehungen der Muster.
     *
     * Eine Folge zählt nur, wenn das Fahrzeug an der Endstelle höchstens eine Stunde steht.
     * Eine längere Pause ist eine Lücke in der Kette, kein Anschluss — ihr Abstand sagte nichts
     * über den Takt.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $fahrten
     * @return array{count: array<string, int>, first: array<string, int>, duration: array<string, int>, stops: array<string, array<int, int>>, next: array<string, array<string, int>>, layover: int}
     */
    private function patterns(array $fahrten): array
    {
        $anzahl = [];
        $erste = [];
        $dauern = [];
        $halte = [];
        $abstaende = [];
        $wenden = [];

        foreach ($fahrten as $liste) {
            foreach ($liste as $k => $f) {
                $p = $f['pattern'];
                $anzahl[$p] = ($anzahl[$p] ?? 0) + 1;
                $halte[$p] ??= $f['stops'];

                if ($f['dep'] !== null) {
                    $erste[$p] = min($erste[$p] ?? PHP_INT_MAX, $f['dep']);
                }

                if ($f['dep'] !== null && $f['arr'] !== null) {
                    $dauern[$p][] = $f['arr'] - $f['dep'];
                }

                $vorher = $liste[$k - 1] ?? null;

                if ($vorher === null || $vorher['arr'] === null || $vorher['dep'] === null || $f['dep'] === null) {
                    continue;
                }

                $wende = $f['dep'] - $vorher['arr'];

                if ($wende >= 0 && $wende <= self::MAX_LAYOVER_SECONDS) {
                    $abstaende[$vorher['pattern']][$p][] = $f['dep'] - $vorher['dep'];
                    $wenden[] = $wende;
                }
            }
        }

        $folge = [];

        foreach ($abstaende as $von => $ziele) {
            foreach ($ziele as $nach => $werte) {
                $folge[$von][$nach] = $this->median($werte);
            }
        }

        return [
            'count' => $anzahl,
            'first' => $erste,
            'duration' => array_map(fn (array $w): int => $this->median($w), $dauern),
            'stops' => $halte,
            'next' => $folge,
            'layover' => $wenden === [] ? 0 : $this->median($wenden),
        ];
    }

    /**
     * Das Muster, mit dem die Runde beginnt: das häufigste der gewählten Linie.
     *
     * So beginnt die Tabelle der 1 mit einer Fahrt der 1, nicht mit dem Zubringer der 5. Bei
     * Gleichstand das früheste — deterministisch, und meist die Richtung, in der der Tag beginnt.
     *
     * @param  array<string, mixed>  $muster
     */
    private function anchorPattern(array $muster, string $line): string
    {
        $kandidaten = array_filter(
            array_keys($muster['count']),
            static fn (string $p): bool => str_starts_with($p, $line.'|'),
        );

        if ($kandidaten === []) {
            $kandidaten = array_keys($muster['count']);
        }

        usort($kandidaten, static fn (string $a, string $b): int => $muster['count'][$b] <=> $muster['count'][$a]
            ?: ($muster['first'][$a] ?? PHP_INT_MAX) <=> ($muster['first'][$b] ?? PHP_INT_MAX)
            ?: strcmp($a, $b));

        return $kandidaten[0];
    }

    /**
     * Der Versatz jedes Musters zum Rundenbeginn, und die Länge einer Runde.
     *
     * Vom Anker aus entlang der Folgebeziehungen, vorwärts wie rückwärts. Schließt sich der
     * Kreis — ein Muster führt zurück auf den Anker —, ist die Runde gemessen. Sonst wird sie
     * geschätzt: von der frühesten bis zur spätesten Fahrt der Runde, plus eine übliche Wende
     * für das fehlende Glied. Bei der 1 heute ist das die Wende am Klinikum Olvenstedt, die in
     * keiner Kette vorkommt.
     *
     * Der Versatz liegt danach in `[0, Runde)`: Was vor dem Anker fährt, gehört ans Ende der
     * vorigen Runde.
     *
     * @param  array<string, mixed>  $muster
     * @return array{0: array<string, int>, 1: int|null}
     */
    private function offsets(array $muster, string $anker): array
    {
        $rueckwaerts = [];

        foreach ($muster['next'] as $von => $ziele) {
            foreach ($ziele as $nach => $abstand) {
                $rueckwaerts[$nach][$von] = $abstand;
            }
        }

        $versatz = [$anker => 0];
        $warteschlange = [$anker];
        $schluss = [];

        while ($warteschlange !== []) {
            $p = array_shift($warteschlange);

            foreach ($muster['next'][$p] ?? [] as $q => $abstand) {
                if ($q === $anker) {
                    $schluss[] = $versatz[$p] + $abstand;
                }

                if (! isset($versatz[$q])) {
                    $versatz[$q] = $versatz[$p] + $abstand;
                    $warteschlange[] = $q;
                }
            }

            foreach ($rueckwaerts[$p] ?? [] as $r => $abstand) {
                if (! isset($versatz[$r])) {
                    $versatz[$r] = $versatz[$p] - $abstand;
                    $warteschlange[] = $r;
                }
            }
        }

        $schluss = array_values(array_filter($schluss, static fn (int $s): bool => $s > 0));

        if ($schluss !== []) {
            $runde = $this->median($schluss);
        } else {
            $anfang = min($versatz);
            $ende = max(array_map(
                static fn (string $p): int => $versatz[$p] + ($muster['duration'][$p] ?? 0),
                array_keys($versatz),
            ));
            $runde = $ende - $anfang + $muster['layover'];
        }

        if ($runde <= 0) {
            return [[], null];
        }

        return [
            array_map(static fn (int $v): int => (($v % $runde) + $runde) % $runde, $versatz),
            $runde,
        ];
    }

    /**
     * Die Plätze einer Runde: je Stelle im Ring das Muster, das sie prägt.
     *
     * Überschneiden sich zwei Muster in der Zeit, sind sie Varianten derselben Stelle — die 1 ab
     * City Carré und die 1 ab Hbf. Den Platz prägt das häufigere, das andere wird ihm
     * zugeschlagen und findet dort beim Einpassen seine eigenen Zeilen. Ein Muster am Ende der
     * Runde kann über den Rundenbeginn hinausreichen; es wird deshalb auch um eine Runde
     * zurückversetzt verglichen.
     *
     * @param  array<string, mixed>  $muster
     * @param  array<string, int>  $versatz
     * @return array{0: array<int, string>, 1: array<string, int>}
     *                                                             prägende Muster in Rundenfolge, Platz je Muster
     */
    private function slots(array $muster, array $versatz, int $runde): array
    {
        $kandidaten = array_keys($versatz);

        usort($kandidaten, static fn (string $a, string $b): int => $muster['count'][$b] <=> $muster['count'][$a]
            ?: $versatz[$a] <=> $versatz[$b]
            ?: strcmp($a, $b));

        $praegend = [];
        $zu = [];

        foreach ($kandidaten as $p) {
            $bester = null;
            $besteUeberlappung = self::OVERLAP_TOLERANCE_SECONDS;

            foreach ($praegend as $q) {
                foreach ([0, -$runde, $runde] as $verschub) {
                    $von = max($versatz[$p] + $verschub, $versatz[$q]);
                    $bis = min(
                        $versatz[$p] + $verschub + ($muster['duration'][$p] ?? 0),
                        $versatz[$q] + ($muster['duration'][$q] ?? 0),
                    );

                    if ($bis - $von > $besteUeberlappung) {
                        $bester = $q;
                        $besteUeberlappung = $bis - $von;
                    }
                }
            }

            if ($bester === null) {
                $praegend[] = $p;
                $zu[$p] = $p;
            } else {
                $zu[$p] = $bester;
            }
        }

        usort($praegend, static fn (string $a, string $b): int => $versatz[$a] <=> $versatz[$b]);
        $index = array_flip($praegend);

        return [$praegend, array_map(static fn (string $q): int => $index[$q], $zu)];
    }

    /**
     * Die Runde jeder Fahrt — je Umlauf gezählt ab seiner ersten.
     *
     * Der Rundenbeginn einer Fahrt ist ihre Abfahrt minus den Versatz ihres Musters. Zwei
     * Fahrten desselben Umlaufs, deren Rundenbeginn um eine ganze Runde auseinanderliegt,
     * stehen einen Block auseinander — auch wenn dazwischen keine Fahrt bekannt ist. Eine
     * Lücke in der Kette bleibt so als Lücke sichtbar, statt die folgenden Fahrten
     * hochzuziehen.
     *
     * Zwei Fahrten eines Umlaufs dürfen nie auf denselben Platz derselben Runde fallen, und
     * rückwärts geht es nie: Kommt ein Platz, der in dieser Runde schon vorbei ist, beginnt die
     * nächste. Das fängt auch eine knapp geschätzte Rundenlänge ab. Fahrten ohne Versatz (ihr
     * Muster hängt an keiner Folge) stehen auf einem Platz hinter dem Ring.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $fahrten
     * @param  array<string, int>  $versatz
     * @param  array<string, int>  $platzVon
     * @return array{0: array<int, array<int, array{round: int, slot: int}>>, 1: array<int, int>}
     *                                                                                            Runde und Platz je Fahrt, Rundenbeginn der ersten Runde je Umlauf
     */
    private function assignRounds(array $fahrten, array $versatz, array $platzVon, int $plaetze, int $runde): array
    {
        $ergebnis = [];
        $beginn = [];

        foreach ($fahrten as $i => $liste) {
            $ergebnis[$i] = [];
            $r = 0;
            $platzVorher = -1;

            foreach ($liste as $k => $f) {
                $platz = $platzVon[$f['pattern']] ?? $plaetze;
                $v = $f['dep'] !== null && isset($versatz[$f['pattern']]) ? $f['dep'] - $versatz[$f['pattern']] : null;

                if ($v !== null) {
                    // Die erste verortbare Fahrt setzt den Takt dieses Umlaufs.
                    $beginn[$i] ??= $v;
                    $r = max($r, (int) round(($v - $beginn[$i]) / $runde));
                }

                if ($k > 0 && $r === ($ergebnis[$i][$k - 1]['round'] ?? -1) && $platz <= $platzVorher) {
                    $r++;
                }

                $ergebnis[$i][$k] = ['round' => $r, 'slot' => $platz];
                $platzVorher = $platz;
            }
        }

        return [$ergebnis, $beginn];
    }

    /**
     * Setzt die Blöcke zusammen: je Runde des Tages ein Block, darin Platz für Platz.
     *
     * **Feste Runden statt verschobener Spalten.** Block 0 ist die erste Runde des Tages, Block 1
     * die zweite. Wo ein Umlauf im Block steht, ergibt sich aus seinem Rundenbeginn. Die
     * gewöhnliche Ausrichtung verschiebt stattdessen Spalten, damit eine Zeile quer aufsteigt —
     * das setzt voraus, dass die Kursnummern der Fahrtfolge folgen. Im Ring tun sie das nicht
     * (7, 8, 9 fahren ab Klinikum, 2, 18, 19 ab Westerhüsen), und die Spalten treppten sich über
     * viele Blöcke. So steht in einer Zeile dieselbe Stelle des Rings in derselben Runde.
     *
     * **Jeder Platz wird für sich eingepasst.** Sein prägendes Muster gibt die Zeilen vor,
     * Varianten bekommen darin eigene. Über Platzgrenzen hinweg wird nichts verschmolzen: Die
     * Ankunft in Westerhüsen und die Abfahrt dort gehören zu zwei Plätzen und bleiben zwei
     * Zeilen, egal wie sich die Halte gleichen.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $fahrten
     * @param  array<int, array<int, array{round: int, slot: int}>>  $runden
     * @param  array<int, int>  $beginn
     * @param  array<int, string>  $plaetze
     * @param  array<string, mixed>  $muster
     * @return array{0: array<int, int>, 1: array<int, array<int, int>>, 2: bool}
     */
    private function build(
        array $fahrten,
        array $runden,
        array $beginn,
        int $runde,
        array $plaetze,
        array $muster,
    ): array {
        $erste = $beginn === [] ? 0 : min($beginn);

        // Block => Platz => Fahrten dort: [Umlauf, Fahrt]
        $bloecke = [];

        foreach ($fahrten as $i => $liste) {
            $start = isset($beginn[$i]) ? intdiv($beginn[$i] - $erste, $runde) : 0;

            foreach ($liste as $k => $f) {
                $bloecke[$start + $runden[$i][$k]['round']][$runden[$i][$k]['slot']][] = [$i, $k];
            }
        }

        ksort($bloecke);

        $achse = [];
        $zuordnung = array_fill_keys(array_keys($fahrten), []);
        $geruest = 0;

        foreach ($bloecke as $jePlatz) {
            // Alle Plätze des Rings, auch leere — das Gerüst ist die Grundlinie. Der Platz hinter
            // dem Ring nur, wenn dort etwas steht.
            foreach ([...array_keys($plaetze), count($plaetze)] as $platz) {
                $belegt = $jePlatz[$platz] ?? [];
                $praegend = $plaetze[$platz] ?? null;

                if ($praegend === null && $belegt === []) {
                    continue;
                }

                $varianten = $praegend === null ? [] : [['stops' => $muster['stops'][$praegend], 'weight' => self::SKELETON_WEIGHT]];
                $geruest += $praegend === null ? 0 : count($muster['stops'][$praegend]);

                foreach ($belegt as [$i, $k]) {
                    $varianten[] = ['stops' => $fahrten[$i][$k]['stops'], 'weight' => 1];
                }

                $lokal = $this->aligner->align($varianten);
                $basis = count($achse);
                $achse = [...$achse, ...$lokal['axis']];
                $versatzIndex = $praegend === null ? 0 : 1;

                foreach ($belegt as $n => [$i, $k]) {
                    foreach ($lokal['mapping'][$n + $versatzIndex] ?? [] as $position => $zeile) {
                        $zuordnung[$i][$fahrten[$i][$k]['from'] + $position] = $basis + $zeile;
                    }
                }
            }
        }

        return [$achse, $zuordnung, count($achse) > max(1, $geruest) * self::ALIGNMENT_WARNING_RATIO];
    }

    private function known(?int $sortKey): ?int
    {
        return $sortKey === null || $sortKey === PHP_INT_MAX ? null : $sortKey;
    }

    /**
     * @param  array<int, int>  $werte
     */
    private function median(array $werte): int
    {
        sort($werte);

        return $werte[intdiv(count($werte), 2)];
    }
}
