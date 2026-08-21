<?php

declare(strict_types=1);

/**
 * Fachliche Parameter von MD-Takt. Zugangsdaten und Fremdsystem-Endpunkte gehören
 * weiterhin nach `config/services.php` — hier stehen nur Stellschrauben der Fachlogik.
 */
return [

    'consolidation' => [

        /*
         * Ab welchem Anteil gleichzeitig geänderter Linien ein Periodenwechsel vorgeschlagen
         * wird (FAHRPLANPERIODEN §4.3). Bezugsgröße sind die Linien, die an dem betreffenden
         * Tag überhaupt verkehren — ein Anteil statt einer absoluten Zahl, damit die Schwelle
         * nicht kippt, wenn das Netz wächst oder an einem Sonntag weniger Linien fahren.
         *
         * 0.33 (entschieden 21.08.2026): Ein echter Fahrplanwechsel fasst nahezu alle Linien
         * gleichzeitig an, eine einzelne Baustelle drei bis sechs. Der empfindlichere Wert
         * nimmt lieber einen abzulehnenden Vorschlag in Kauf, als einen realen Wechsel zu
         * übersehen.
         */
        'period_offer_min_share' => (float) env('PERIOD_OFFER_MIN_SHARE', 0.33),

    ],

];
