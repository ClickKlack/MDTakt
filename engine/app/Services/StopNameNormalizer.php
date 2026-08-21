<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Normalisiert Haltestellennamen für den Identitätsvergleich (FAHRPLANPERIODEN §5.1).
 *
 * Der Feed führt denselben Halt in mehreren Schreibweisen: `Listemannstr.` neben
 * `Listemannstraße`, `Magdeburg, Zoo` neben `Zoo`, `Magdeburg, Hohenwarther Str.` neben
 * `Hohenwarther Straße`. Ohne Normalisierung fielen diese Paare bei der Dedup auseinander,
 * obwohl sie wenige Meter auseinanderliegen und derselbe Halt sind.
 *
 * Nicht eingeebnet werden unterscheidende Zusätze — `(Schleife)`, `(Quittenweg)`,
 * `Wendeschl.`, `Hst. 2`, `Ersatzh.`: Genau die trennen im MVB-Netz die Halte, die
 * dicht beieinander liegen und trotzdem eigenständig sind.
 */
final class StopNameNormalizer
{
    public function normalize(string $name): string
    {
        $s = mb_strtolower($name);

        // Ortspräfix: der Feed führt Magdeburger Halte teils mit, teils ohne.
        $s = (string) preg_replace('/^magdeburg[,\s]+/u', '', $s);

        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        // „Str." / „str" als Kompositum-Endung ausschreiben (Listemannstr. → listemannstrasse).
        $s = (string) preg_replace('/str\.?(?=$|[^a-z])/u', 'strasse', $s);

        // Satzzeichen und Leerraum tragen keine Bedeutung — „City Carré" = „citycarre".
        return (string) preg_replace('/[^a-z0-9]/u', '', $s);
    }
}
