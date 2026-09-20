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

    'operating_day' => [

        /*
         * Betriebstag-Wechsel (entschieden 20.09.2026). Eine Fahrt, die vor dieser Uhrzeit
         * beginnt, gehört zum Betriebstag des **Vortags** — so, wie Verkehrsbetriebe es sonst
         * mit Zeiten jenseits 24:00 ausdrücken („26:00" für 2 Uhr des Folgetags).
         *
         * Der gtfs.de-Feed nutzt diese Konvention **nicht**: Keine einzige Fahrt im Bestand
         * beginnt jenseits 24:00, alles hängt am Kalendertag. Der Betriebstag muss deshalb
         * hier rekonstruiert werden.
         *
         * **Zwei Grenzen, weil eine nicht reicht.** Am Realbestand gemessen:
         *   - Taglinien fahren von 03:47 bis 23:5x — davor nichts.
         *   - Nachtlinien fahren 00:10–06:40 und 22:00–23:59; zwischen 07:00 und 21:59
         *     verkehrt keine einzige.
         * Beide Netze überlappen also von 03:45 bis 06:40. Eine gemeinsame Grenze müsste dort
         * zwangsläufig etwas falsch zuordnen; getrennte Grenzen sind dagegen eindeutig — die
         * Lücke von 07:00 bis 22:00 macht jeden Wert dazwischen für Nachtlinien wasserdicht.
         *
         * Ohne diese Trennung zerfällt der `mo_fr`-Strang der Nachtlinien: Die Nacht von
         * Sonntag auf Montag ist eine Sonntagnacht, GTFS ordnet sie aber dem Montag zu
         * (FAHRPLANPERIODEN §8). N1 bekäme montags einen anderen Fahrplan als Di–Fr.
         */
        'day_line_boundary' => env('OPERATING_DAY_BOUNDARY', '03:00'),
        'night_line_boundary' => env('OPERATING_DAY_NIGHT_BOUNDARY', '12:00'),

    ],

];
