<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use App\Models\SchedulePeriod;

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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forLine(string $line, SchedulePeriod $period, FahrplanTyp $typ, ?int $standIndex = null): array
    {
        $uebersicht = $this->overview->forLine($line, $period, $typ, withStops: true, standIndex: $standIndex);

        [$varianten, $spalten, $namen] = $this->collect($uebersicht['courses']);

        if ($varianten === []) {
            return $this->antwort($uebersicht, [], [], false);
        }

        // Die Haltestelle, an der sich die Umlaeufe messen lassen, und die Laenge einer Runde.
        $takt = $this->anchor($varianten, $spalten);

        [$achse, $zuordnung, $warnung] = $takt === null
            ? $this->alignWhole($varianten)
            : $this->alignByRound($varianten, $spalten, $takt);

        foreach ($spalten as $i => &$spalte) {
            $spalte['cells'] = $this->place($spalte['cells'], $zuordnung[$i] ?? [], count($achse));
        }
        unset($spalte);

        return $this->antwort($uebersicht, $this->rows($achse, $namen), $spalten, $warnung);
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
     * @param  array<int, array<string, mixed>>  $zeilen
     * @param  array<int, array<string, mixed>>  $spalten
     * @return array<string, mixed>
     */
    private function antwort(array $uebersicht, array $zeilen, array $spalten, bool $warnung): array
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
            'rows' => $zeilen,
            'courses' => $spalten,
            'alignment_warning' => $warnung,
            // Dieselben Kennzahlen und dieselbe Liste wie die Kettenansicht: Der Umschalter
            // soll den Pflegestand nicht verändern, nur die Darstellung.
            'unassigned' => $uebersicht['unassigned'],
            'summary' => $uebersicht['summary'],
        ];
    }
}
