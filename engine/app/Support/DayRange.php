<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ein Zeitraum ganzer **Betriebstage**, beidseitig einschließend.
 *
 * Bewusst auf `Y-m-d`-Zeichenketten gerechnet und nicht auf Datumsobjekten: Die Vergleiche sind
 * damit lexikografisch und exakt, und es gibt keine Uhrzeit, die sich einschleichen könnte. Die
 * `date`-Spalten tragen unter SQLite eine Uhrzeit, unter PostgreSQL nicht (siehe CLAUDE.md) —
 * hier wird beides auf zehn Zeichen beschnitten und damit gleich behandelt.
 *
 * Kein Kalendertag: Eine N1-Fahrt um 01:45 läuft kalendarisch am Samstag und gehört zum
 * Betriebstag Freitag. `line_version_intervals` steht bereits in dieser Rechnung.
 */
final readonly class DayRange
{
    private function __construct(
        public string $from,
        public string $to,
    ) {}

    public static function fromStrings(string $von, string $bis): self
    {
        return new self(substr($von, 0, 10), substr($bis, 0, 10));
    }

    /**
     * Einzelne Tage zu zusammenhängenden Spannen zusammenfassen.
     *
     * Aus den Tagen einer `mo_fr`-Version wird so je Woche eine Spanne Mo–Fr statt fünf
     * Einzeltage — die Prüfungen rechnen damit über Spannen, nicht über Tagesmengen.
     *
     * @param  array<int, string>  $tage  `Y-m-d`, Reihenfolge beliebig
     * @return array<int, self>
     */
    public static function fromDays(array $tage): array
    {
        sort($tage);

        $spannen = [];
        $von = null;
        $letzter = null;

        foreach ($tage as $tag) {
            if ($letzter !== null && self::verschoben($letzter, 1) === $tag) {
                $letzter = $tag;

                continue;
            }

            if ($von !== null) {
                $spannen[] = new self($von, (string) $letzter);
            }

            $von = $tag;
            $letzter = $tag;
        }

        if ($von !== null) {
            $spannen[] = new self($von, (string) $letzter);
        }

        return $spannen;
    }

    public function touches(self $andere): bool
    {
        return $this->from <= $andere->to && $andere->from <= $this->to;
    }

    /**
     * Berühren sich zwei Mengen von Zeiträumen an mindestens einem Tag?
     *
     * @param  array<int, self>  $a
     * @param  array<int, self>  $b
     */
    public static function overlap(array $a, array $b): bool
    {
        foreach ($a as $links) {
            foreach ($b as $rechts) {
                if ($links->touches($rechts)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Die Tage aus `$von`, die `$ab` **nicht** abdeckt.
     *
     * Damit beantwortet das Verzeichnis „ist hier noch etwas zu tun": Eine Fahrt ist offen,
     * solange ihre Anschlüsse nicht alle ihre Tage abdecken — auch wenn schon einer dranhängt.
     * Ohne diese Rechnung sähe eine Fahrt, die nach einem Versionswechsel einen zweiten
     * Anschluss braucht, fertig aus.
     *
     * @param  array<int, self>  $von
     * @param  array<int, self>  $ab
     * @return array<int, self>
     */
    public static function subtract(array $von, array $ab): array
    {
        $rest = $von;

        foreach ($ab as $weg) {
            $neu = [];

            foreach ($rest as $spanne) {
                if (! $spanne->touches($weg)) {
                    $neu[] = $spanne;

                    continue;
                }

                if ($spanne->from < $weg->from) {
                    $neu[] = new self($spanne->from, self::verschoben($weg->from, -1));
                }

                if ($spanne->to > $weg->to) {
                    $neu[] = new self(self::verschoben($weg->to, 1), $spanne->to);
                }
            }

            $rest = $neu;
        }

        return $rest;
    }

    private static function verschoben(string $tag, int $tage): string
    {
        return (new \DateTimeImmutable($tag))->modify(sprintf('%+d day', $tage))->format('Y-m-d');
    }

    /**
     * Die gemeinsamen Tage zweier Mengen.
     *
     * Nicht zusammengefasst: Je Version stehen ohnehin nur wenige Intervalle an, und eine
     * Normalisierung brächte nichts, was die Prüfungen hier bräuchten.
     *
     * @param  array<int, self>  $a
     * @param  array<int, self>  $b
     * @return array<int, self>
     */
    public static function intersect(array $a, array $b): array
    {
        $ergebnis = [];

        foreach ($a as $links) {
            foreach ($b as $rechts) {
                if (! $links->touches($rechts)) {
                    continue;
                }

                $ergebnis[] = new self(
                    max($links->from, $rechts->from),
                    min($links->to, $rechts->to),
                );
            }
        }

        return $ergebnis;
    }
}
