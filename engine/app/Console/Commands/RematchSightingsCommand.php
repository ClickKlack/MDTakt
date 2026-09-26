<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SightingIngestService;
use Illuminate\Console\Command;

/**
 * Ordnet offene Sichtungen erneut zu. Läuft automatisch am Ende jedes GTFS-Imports; von Hand
 * gebraucht nach einer Änderung an der Zuordnung oder nach `schedule:consolidate`.
 *
 * Ein Aufruf von Hand zählt **nicht** als Import: Er schiebt keine Sichtung von „wartet auf
 * Fahrplan" nach „keine Fahrt".
 */
final class RematchSightingsCommand extends Command
{
    protected $signature = 'sightings:rematch';

    protected $description = 'Offene Sichtungen aus MDKursTracker erneut Fahrten zuordnen';

    public function handle(SightingIngestService $service): int
    {
        $zahlen = $service->rematchOpen(countAttempt: false);

        $this->table(['Kennzahl', 'Wert'], [
            ['Geprüft', $zahlen['checked']],
            ['Mit Fahrt', $zahlen['matched']],
            ['Weiter offen', $zahlen['still_open']],
            ['Neu bestätigt', $zahlen['confirmed']],
        ]);

        return self::SUCCESS;
    }
}
