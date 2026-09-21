<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Die beiden Richtungen des Mengen-Laufs im Haltestellen-Editor.
 *
 * Heißt `action` und nicht `mode`: `mode` ist im ganzen Bestand das **Verkehrsmittel**
 * (`tram`/`bus`), und zwei Bedeutungen unter einem Namen wären ausgerechnet an der Stelle
 * verwirrend, an der eine Verwechslung Daten löscht.
 */
enum AutoLinkAction: string
{
    case Link = 'link';
    case Unlink = 'unlink';

    public function label(): string
    {
        return match ($this) {
            self::Link => 'Anschlüsse anlegen',
            self::Unlink => 'Anschlüsse auflösen',
        };
    }
}
