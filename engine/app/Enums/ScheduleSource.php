<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Aus welchem Bestand eine Fahrplan-Antwort kommt.
 *
 * `Raw` ist der importierte GTFS-Bestand: nur das aktuelle Feed-Fenster (rund drei Wochen),
 * bei jedem Import ersetzt. `Consolidated` ist der dauerhafte Bestand, der über viele Importe
 * zusammenwächst und auch vergangene Fahrpläne kennt (FAHRPLANPERIODEN Phase C).
 *
 * Die Admin-Schaltzentrale kann zwischen beiden umschalten — der Roh-Bestand bleibt als
 * Kontrollblick auf das, was der letzte Import wirklich geliefert hat. Der öffentliche Viewer
 * sieht ausschließlich das Konsolidat.
 */
enum ScheduleSource: string
{
    case Raw = 'raw';
    case Consolidated = 'consolidated';

    public function label(): string
    {
        return match ($this) {
            self::Raw => 'Roh-Bestand (letzter Import)',
            self::Consolidated => 'Konsolidat (dauerhaft)',
        };
    }
}
