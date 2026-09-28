<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Vergleicht einen HAFAS-Haltnamen (MDKursTracker) mit einem GTFS-Haltnamen — für die Kursauskunft.
 *
 * Bewusst getrennt vom {@see StopNameNormalizer}: Der erzeugt den gespeicherten `name_key` der
 * Konsolidierung und muss stabil bleiben. Hier geht es nur darum, unter den wenigen Fahrten einer
 * Linie zur selben Minute den richtigen Halt zu erkennen — dafür darf der Vergleich großzügig sein.
 *
 * Was sich zwischen HAFAS und Feed unterscheidet (erster produktiver Lauf, 28.09.2026):
 * - Abkürzungen im Feed: `S-Bhf. Eichenweiler`, `S-Bf. Buckau`, `Hbf / Willy-Brandt-Platz`,
 *   `Benediktinerstr./Ges.-Haus`, `Halberst. Str./Leipz. Str.`, `Hochschule Magd.-Stendal`,
 *   `Industrie- u. Logistik-Centrum`, `Turmschanzenstr./Friedensbr.`
 * - Zusätze in HAFAS: `Barleber See (Tram/Bus)`, `Herrenkrug (Tram)`, `Opernhaus (Listemannstr.)`,
 *   Stadtteil vorn: `Sudenburg, Braunlager Str.`
 */
final class StopNameMatcher
{
    /** Abkürzungen, die kein Wortanfang des vollen Worts sind — die übrigen deckt die Präfix-Regel ab. */
    private const WOERTER = [
        'hbf' => 'hauptbahnhof',
        'bhf' => 'bahnhof',
        'bf' => 'bahnhof',
    ];

    /**
     * @param  bool  $loose  auch ohne Klammerzusatz vergleichen — `Opernhaus (Listemannstr.)` = `Opernhaus`.
     *                       Nur als zweite Stufe: Klammern trennen im Feed eigenständige Halte
     *                       (`Rothensee (Schleife)` neben `Rothensee`).
     */
    public function matches(string $a, string $b, bool $loose = false): bool
    {
        foreach ($this->variants($a, $loose) as $va) {
            foreach ($this->variants($b, $loose) as $vb) {
                if ($this->covers($va, $vb) || $this->covers($vb, $va)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Schreibweisen desselben Namens: vollständig, ohne Verkehrsmittel-Zusatz, ohne vorangestellten
     * Stadtteil — und nur locker ohne jeden Klammerzusatz.
     *
     * @return array<int, string>
     */
    private function variants(string $name, bool $loose): array
    {
        $s = mb_strtolower(trim($name));
        $s = (string) preg_replace('/^magdeburg[,\s]+/u', '', $s);
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e']);

        $varianten = [$s];
        $varianten[] = trim((string) preg_replace('/\s*\((tram|bus|tram\/bus|bus\/tram)\)\s*$/u', '', $s));

        if ($loose) {
            $varianten[] = trim((string) preg_replace('/\s*\([^)]*\)/u', '', $s));
        }

        // „Sudenburg, Braunlager Str." — der Feed führt nur den Straßennamen.
        foreach ($varianten as $v) {
            if (preg_match('/^[^,]+,\s*(.+)$/u', $v, $m) === 1) {
                $varianten[] = $m[1];
            }
        }

        return array_values(array_unique(array_filter($varianten, static fn (string $v): bool => $v !== '')));
    }

    /**
     * Deckt `$kurz` (mit Abkürzungen) den Namen `$lang` ab? Ein Wort mit Punkt steht für jedes Wort,
     * das so beginnt („halberst." → „halberstaedter").
     */
    private function covers(string $kurz, string $lang): bool
    {
        return preg_match('/^'.$this->pattern($kurz).'$/u', $this->compact($lang)) === 1;
    }

    private function pattern(string $s): string
    {
        $s = $this->expand($s);
        $muster = '';

        foreach (preg_split('/([a-z0-9]+\.?)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $teil) {
            if (preg_match('/^[a-z0-9]+\.?$/u', $teil) !== 1) {
                continue;
            }

            $muster .= str_ends_with($teil, '.')
                ? preg_quote(rtrim($teil, '.'), '/').'[a-z0-9]*'
                : preg_quote($teil, '/');
        }

        return $muster;
    }

    private function compact(string $s): string
    {
        return (string) preg_replace('/[^a-z0-9]/u', '', $this->expand($s));
    }

    /** „Str." und die Bahnhofs-Kürzel ausschreiben — auf beiden Seiten gleich. */
    private function expand(string $s): string
    {
        $s = (string) preg_replace('/str\.?(?=$|[^a-z])/u', 'strasse', $s);

        return (string) preg_replace_callback(
            '/(?<![a-z])(hbf|bhf|bf)\.?(?![a-z])/u',
            static fn (array $m): string => self::WOERTER[$m[1]],
            $s,
        );
    }
}
