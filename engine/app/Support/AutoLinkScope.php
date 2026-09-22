<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AutoLinkAction;
use App\Enums\FahrplanTyp;
use App\Models\SchedulePeriod;
use App\Models\StopGroup;

/**
 * Was ein Mengen-Lauf im Haltestellen-Editor anfassen darf: Ausschnitt, Filter und Grenzen.
 *
 * Als eigenes Objekt, weil zehn Einzelparameter an einer Methodensignatur nicht mehr zu lesen
 * wären — und weil der Filter hier fachlich zusammengehört: Er ist nicht Beiwerk, sondern die
 * Zusicherung, dass der Lauf nur anfasst, was der Pflegende vor sich sieht.
 */
final readonly class AutoLinkScope
{
    /**
     * @param  array<int, string>  $lines  Leer = alle Linien der Haltestelle.
     * @param  string|null  $mode  Verkehrsmittel (`tram`/`bus`), nicht die Aktion.
     * @param  int  $fromTripId  Markierte Startfahrt der **linken** Spalte (endende Fahrten).
     * @param  int  $toTripId  Markierte letzte Fahrt, ebenfalls links.
     * @param  bool  $includeTerminals  Nur bei {@see AutoLinkAction::Unlink}: auch Aus- und Einrücken lösen.
     * @param  bool  $throughStop  Nur bei {@see AutoLinkAction::Link}: Tauschpunkt statt Wendestelle.
     */
    public function __construct(
        public StopGroup $stopGroup,
        public SchedulePeriod $period,
        public FahrplanTyp $dayType,
        public ?int $standIndex,
        public array $lines,
        public ?string $mode,
        public int $fromTripId,
        public int $toTripId,
        public int $minTurnaroundSeconds,
        public int $maxTurnaroundSeconds,
        public AutoLinkAction $action,
        public bool $includeTerminals,
        public bool $throughStop = false,
    ) {}

    /**
     * Passt eine Fahrt in den Filter?
     *
     * **Wortgleich zur Anzeige** (`StopLinkBoard.vue::passt()`). Laufen die beiden auseinander,
     * verknüpft der Lauf etwas, das der Pflegende gar nicht sieht — der einzige Schaden, den
     * diese Funktion anrichten kann.
     *
     * @param  array<string, mixed>  $trip
     */
    public function matches(array $trip): bool
    {
        if ($this->mode !== null && $trip['mode'] !== $this->mode) {
            return false;
        }

        return $this->lines === [] || in_array($trip['line'], $this->lines, true);
    }
}
