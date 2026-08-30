<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Bringt mehrere Haltefolgen auf eine gemeinsame Zeilenachse (Fahrplanbuch-Darstellung).
 *
 * **Warum das nötig ist:** Eine Richtung einer Linie wird nicht von allen Fahrten gleich
 * befahren — Kurzläufer, Ausrückfahrten und Baustellen-Umleitungen weichen ab. Im MVB-Netz
 * betrifft das 58 von 965 Richtungsgruppen, mit bis zu sieben Varianten. Damit sie in *einer*
 * Tabelle stehen können, braucht es eine Achse, in die sich jede Variante einordnet.
 *
 * **Warum keine Halt-Identität als Zeile:** 1.022 von 18.193 Fahrten berühren denselben Halt
 * zweimal (Wendeschleifen, Stichabstecher — die Steige sind im Konsolidat bewusst verschmolzen,
 * FAHRPLANPERIODEN §5.1). Eine Zeile ist deshalb eine **Position** in der Achse, keine Identität;
 * derselbe Halt darf mehrfach auftreten.
 *
 * **Verfahren:** Progressive Verschmelzung über die längste gemeinsame Teilfolge. Die häufigste
 * Variante prägt das Gerüst, jede weitere wird eingepasst; was nicht übereinstimmt, wird als
 * zusätzliche Zeile eingefügt.
 *
 * **Sicherheitseigenschaft:** Der Algorithmus ist eine Heuristik — bei ungewöhnlich verdrehten
 * Varianten kann die Achse länger geraten als nötig. Er darf aber **nie** eine Zeit an der
 * falschen Stelle zeigen: Jede Zuordnung bildet Position auf Position ab, und ein Halt wird nur
 * dann auf eine bestehende Zeile gelegt, wenn dort dieselbe Identität steht. Ein Fehler äußert
 * sich also in überflüssigen Zeilen, nie in einer falschen Uhrzeit.
 */
final class StopSequenceAligner
{
    /**
     * @param  array<int, array{stops: array<int, int>, weight: int}>  $variants
     * @return array{axis: array<int, int>, mapping: array<int, array<int, int>>}
     *                                                                            `axis` sind die Halt-Identitäten in Zeilenreihenfolge,
     *                                                                            `mapping[variante][position] = zeilenindex`
     */
    public function align(array $variants): array
    {
        $reihenfolge = $this->mergeOrder($variants);

        if ($reihenfolge === []) {
            return ['axis' => [], 'mapping' => []];
        }

        $erste = array_shift($reihenfolge);
        $axis = array_values($variants[$erste]['stops']);
        $mapping = [$erste => array_keys($axis)];

        foreach ($reihenfolge as $index) {
            [$axis, $axisMap, $seqMap] = $this->merge($axis, array_values($variants[$index]['stops']));

            // Die Achse ist gewachsen — bereits vergebene Zeilen verschieben sich mit.
            foreach ($mapping as $variante => $positionen) {
                $mapping[$variante] = array_map(static fn (int $alt): int => $axisMap[$alt], $positionen);
            }

            $mapping[$index] = $seqMap;
        }

        return ['axis' => $axis, 'mapping' => $mapping];
    }

    /**
     * Verschmelzungsreihenfolge: häufigste Variante zuerst, bei Gleichstand die längere, dann
     * der Reihe nach. Vollständig deterministisch — dieselbe Eingabe ergibt dieselbe Achse.
     *
     * @param  array<int, array{stops: array<int, int>, weight: int}>  $variants
     * @return array<int, int>
     */
    private function mergeOrder(array $variants): array
    {
        $reihenfolge = [];

        foreach ($variants as $index => $variante) {
            if ($variante['stops'] !== []) {
                $reihenfolge[] = $index;
            }
        }

        usort($reihenfolge, static function (int $a, int $b) use ($variants): int {
            return $variants[$b]['weight'] <=> $variants[$a]['weight']
                ?: count($variants[$b]['stops']) <=> count($variants[$a]['stops'])
                ?: $a <=> $b;
        });

        return $reihenfolge;
    }

    /**
     * Verschmilzt eine Folge in die Achse und liefert beide Zuordnungen auf die neue Achse.
     *
     * @param  array<int, int>  $axis
     * @param  array<int, int>  $seq
     * @return array{0: array<int, int>, 1: array<int, int>, 2: array<int, int>}
     */
    private function merge(array $axis, array $seq): array
    {
        $neu = [];
        $axisMap = [];
        $seqMap = [];
        $i = 0;
        $j = 0;

        foreach ($this->commonSubsequence($axis, $seq) as [$ai, $sj]) {
            // Was vor dem Treffer nur in der Achse steht, bleibt erhalten …
            while ($i < $ai) {
                $axisMap[$i] = count($neu);
                $neu[] = $axis[$i];
                $i++;
            }

            // … was nur in der neuen Folge steht, kommt als zusätzliche Zeile dazu.
            while ($j < $sj) {
                $seqMap[$j] = count($neu);
                $neu[] = $seq[$j];
                $j++;
            }

            $axisMap[$i] = $seqMap[$j] = count($neu);
            $neu[] = $axis[$i];
            $i++;
            $j++;
        }

        while ($i < count($axis)) {
            $axisMap[$i] = count($neu);
            $neu[] = $axis[$i];
            $i++;
        }

        while ($j < count($seq)) {
            $seqMap[$j] = count($neu);
            $neu[] = $seq[$j];
            $j++;
        }

        return [$neu, $axisMap, $seqMap];
    }

    /**
     * Längste gemeinsame Teilfolge als Liste von Positionspaaren, aufsteigend.
     *
     * Bewusst über Positionen und nicht über eine Identitäts-Suche: Nur so bleiben mehrfach
     * berührte Halte auseinandergehalten.
     *
     * @param  array<int, int>  $a
     * @param  array<int, int>  $b
     * @return array<int, array{0: int, 1: int}>
     */
    private function commonSubsequence(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        // Klassische DP-Tabelle. Die Folgen sind kurz (im Realbestand höchstens ~60 Halte),
        // ein optimiertes Verfahren wäre hier nur schwerer zu lesen.
        $laenge = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $laenge[$i][$j] = $a[$i] === $b[$j]
                    ? $laenge[$i + 1][$j + 1] + 1
                    : max($laenge[$i + 1][$j], $laenge[$i][$j + 1]);
            }
        }

        $paare = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $paare[] = [$i, $j];
                $i++;
                $j++;
            } elseif ($laenge[$i + 1][$j] >= $laenge[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $paare;
    }
}
