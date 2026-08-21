<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ScheduleVersionService;
use App\Services\TripSignatureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Baut Signaturen, Linien-Versionen und das Konsolidat aus dem vorhandenen Roh-Bestand neu auf.
 *
 * Im Normalbetrieb läuft das automatisch beim Import-Abschluss. Von Hand gebraucht wird es in
 * genau zwei Fällen:
 *
 * 1. **Nach einem Deployment, das die Konsolidat-Tabellen neu anlegt.** Die Tabellen sind dann
 *    leer, und die öffentlichen Endpunkte antworten per Vorgabe aus dem Konsolidat — bis zum
 *    nächsten Import sähe die API sonst leer aus, obwohl Roh-Daten vorliegen.
 * 2. **Nach einer Änderung an der Konsolidierungslogik**, die auf den bestehenden Bestand
 *    angewendet werden soll, ohne auf den nächsten Feed zu warten.
 *
 * Der Lauf ist idempotent: Unveränderte Versionen werden nicht neu geschrieben.
 */
final class ConsolidateScheduleCommand extends Command
{
    protected $signature = 'schedule:consolidate';

    protected $description = 'Signaturen, Linien-Versionen und Konsolidat aus dem Roh-Bestand fortschreiben';

    public function handle(TripSignatureService $signatures, ScheduleVersionService $versions): int
    {
        $this->info('Konsolidierung gestartet …');

        $signaturen = $signatures->rebuild();
        $this->line("  Signaturen: {$signaturen}");

        $ergebnis = $versions->updateFromCurrentImport();

        $this->table(
            ['Kennzahl', 'Wert'],
            [
                ['Versionen neu', $ergebnis['versions_created']],
                ['Intervalle geschrieben', $ergebnis['intervals_written']],
                ['Linien geändert', $ergebnis['lines_changed']],
                ['Halte konsolidiert', $ergebnis['consolidated_stops']],
                ['Fahrten geschrieben', $ergebnis['consolidated_trips']],
            ],
        );

        if ($ergebnis['consolidated_stops'] === 0) {
            $this->warn('Kein Roh-Bestand gefunden — erst importieren, dann konsolidieren.');
        }

        Log::info('Schedule consolidation run from CLI', $ergebnis);

        return self::SUCCESS;
    }
}
