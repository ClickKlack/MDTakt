<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Entfaltet eine Kursnummern-Folge wie `1-8`, `31-35` oder `1-4, 7, 9-12`.
 *
 * Häufig läuft eine Linie ihre Kurse in fester chronologischer Abfolge: Die 6 fährt die Kurse
 * 1–8, und bei gleichbleibendem Takt setzt sich das fort. Kennt man die Menge der Nummern, lässt
 * sie sich fortschreiben, statt jede Spalte einzeln zu tippen.
 *
 * **Führende Nullen bleiben erhalten, und das ist keine Kosmetik.** `courses.number` ist
 * `varchar(8)`; `03` und `3` sind für die Datenbank zwei verschiedene Kurse, und der Bestand
 * schreibt `03`. Die Breite folgt deshalb der Eingabe: `01-08` ergibt `01`…`08`, `1-8` dagegen
 * `1`…`8`. Wer es gepolstert haben will, schreibt es gepolstert hin — geraten wird nichts.
 *
 * **Die Nummern dürfen nie als PHP-Array-Schlüssel dienen.** `"3"` würde dabei zu `int 3` und
 * fiele mit `"03"` zusammen, sobald beides vorkommt. Überall Werte, nirgends Schlüssel.
 */
final class CourseNumberSequence
{
    /**
     * Mehr als das kann keine sinnvolle Eingabe meinen, und `1-99999999` fräße sonst den
     * Speicher. Eine Linie hat Kurse im zweistelligen Bereich.
     */
    private const MAX_NUMBERS = 500;

    /** Die Spaltenbreite von `courses.number`. */
    private const MAX_LENGTH = 8;

    /**
     * @return array<int, string>
     *
     * @throws InvalidArgumentException mit einer Meldung, die direkt in den 422-Envelope passt
     */
    public static function parse(string $input): array
    {
        $tokens = preg_split('/[,;]/', $input) ?: [];
        $nummern = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            foreach (self::expand($token) as $nummer) {
                $nummern[] = $nummer;
            }

            if (count($nummern) > self::MAX_NUMBERS) {
                throw new InvalidArgumentException(sprintf(
                    'Die Folge ergibt mehr als %d Nummern. Ein Umlauf-Muster ist kürzer — prüfe die Bereichsgrenzen.',
                    self::MAX_NUMBERS,
                ));
            }
        }

        if ($nummern === []) {
            throw new InvalidArgumentException(
                'Gib eine Nummernfolge an, zum Beispiel „1-8", „31-35" oder „1-4, 7, 9-12".',
            );
        }

        return $nummern;
    }

    /**
     * Ein Token: entweder eine einzelne Nummer oder ein Bereich `A-B`.
     *
     * @return array<int, string>
     */
    private static function expand(string $token): array
    {
        if (! str_contains($token, '-')) {
            return [self::einzelne($token)];
        }

        $teile = array_map(trim(...), explode('-', $token));

        if (count($teile) !== 2 || $teile[0] === '' || $teile[1] === '') {
            throw new InvalidArgumentException(sprintf(
                'Der Bereich „%s" ist unvollständig. Erwartet wird etwas wie „1-8".',
                $token,
            ));
        }

        [$von, $bis] = $teile;

        if (! ctype_digit($von) || ! ctype_digit($bis)) {
            throw new InvalidArgumentException(sprintf(
                'Ein Bereich braucht Zahlen an beiden Enden — „%s" hat keine.',
                $token,
            ));
        }

        $breite = self::breite($von, $bis);
        $anfang = (int) $von;
        $ende = (int) $bis;

        // Absteigend ist erlaubt: In der Gegenrichtung zählt man durchaus rückwärts, und es
        // abzuweisen wäre eine Schranke ohne Grund.
        $schritt = $anfang <= $ende ? 1 : -1;
        $nummern = [];

        for ($i = $anfang; $schritt > 0 ? $i <= $ende : $i >= $ende; $i += $schritt) {
            $nummern[] = self::pruefeLaenge(str_pad((string) $i, $breite, '0', STR_PAD_LEFT), $token);

            if (count($nummern) > self::MAX_NUMBERS) {
                break;
            }
        }

        return $nummern;
    }

    /**
     * Eine einzelne Nummer. Sie muss **nicht** numerisch sein — `courses.number` ist ein
     * Textfeld, und eine Kursbezeichnung wie `A1` soll sich eintippen lassen.
     */
    private static function einzelne(string $token): string
    {
        return self::pruefeLaenge($token, $token);
    }

    private static function pruefeLaenge(string $nummer, string $token): string
    {
        if (mb_strlen($nummer) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Die Kursnummer „%s" ist länger als %d Zeichen — so lang kann sie nicht gespeichert werden.',
                $token,
                self::MAX_LENGTH,
            ));
        }

        return $nummer;
    }

    /**
     * Auf welche Breite wird gepolstert?
     *
     * Nur, wenn eines der Enden selbst gepolstert geschrieben ist. `01-08` meint `01`…`08`,
     * `1-8` meint `1`…`8`, und `08-12` meint `08`…`12` — dort trägt das eine Ende die Absicht
     * und das andere die Länge.
     */
    private static function breite(string $von, string $bis): int
    {
        $gepolstert = (mb_strlen($von) > 1 && str_starts_with($von, '0'))
            || (mb_strlen($bis) > 1 && str_starts_with($bis, '0'));

        return $gepolstert ? max(mb_strlen($von), mb_strlen($bis)) : 1;
    }
}
