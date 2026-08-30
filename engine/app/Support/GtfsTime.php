<?php

declare(strict_types=1);

namespace App\Support;

/**
 * GTFS-Zeitangaben als Betriebstag-Sekunden.
 *
 * GTFS-Zeiten sind **Netz-Lokalzeit relativ zum Betriebstag**, keine Uhrzeiten im üblichen Sinn:
 * Eine Nachtfahrt um halb zwei steht als `25:30:00`, weil sie zum Betriebstag des Vortags gehört.
 * Sie dürfen deshalb weder in eine Zeitzone umgerechnet noch als `H:i:s` geparst werden.
 *
 * Zweitens sind sie **nicht garantiert nullgepadded** — die GTFS-Spezifikation erlaubt `7:00:00`
 * neben `07:00:00`. Genau daran scheitert ein lexikalischer Vergleich: `"7:00:00" > "23:50:00"`
 * ist als String wahr, als Zeit falsch. Der Bestand sortiert an einer Stelle noch so
 * (`ConsolidatedScheduleService::groupedByStartEnd()`); neuer Code soll das nicht erben.
 */
final class GtfsTime
{
    /**
     * Sekunden seit Betriebstag-Beginn. Werte jenseits von 24 Stunden bleiben erhalten —
     * `25:30:00` ergibt 91800, nicht 5400.
     */
    public static function toSeconds(?string $time): ?int
    {
        if ($time === null || ! preg_match('/^\s*(\d{1,3}):([0-5]\d)(?::([0-5]\d))?\s*$/', $time, $t)) {
            return null;
        }

        return ((int) $t[1]) * 3600 + ((int) $t[2]) * 60 + ((int) ($t[3] ?? 0));
    }

    /**
     * Anzeigeform `HH:MM` mit führender Null, Stunden über 24 unverändert (`25:30`).
     * Sekunden fallen weg — ein Fahrplan zeigt sie nicht.
     */
    public static function toClock(?string $time): ?string
    {
        $sekunden = self::toSeconds($time);

        if ($sekunden === null) {
            return null;
        }

        return sprintf('%02d:%02d', intdiv($sekunden, 3600), intdiv($sekunden % 3600, 60));
    }

    /**
     * Vergleichsfunktion für `usort`. Nicht lesbare Zeiten sortieren ans Ende, damit eine
     * kaputte Angabe nicht stillschweigend als „fährt zuerst" erscheint.
     */
    public static function compare(?string $a, ?string $b): int
    {
        return (self::toSeconds($a) ?? PHP_INT_MAX) <=> (self::toSeconds($b) ?? PHP_INT_MAX);
    }
}
