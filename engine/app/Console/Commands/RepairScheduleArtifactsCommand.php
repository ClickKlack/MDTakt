<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ScheduleRepairService;
use Illuminate\Console\Command;

/**
 * Räumt die Spuren weg, die die alte Rand- und Wechsel-Erkennung hinterlassen hat
 * (FAHRPLANPERIODEN §4.3, §10).
 *
 * Einmal je Bestand zu laufen — in Entwicklung wie in Produktion, jeweils nach dem
 * Deployment der korrigierten Regeln. Danach entstehen die Artefakte nicht neu.
 *
 * Der Lauf ist idempotent: Ein zweiter Aufruf findet nichts mehr und meldet das.
 */
final class RepairScheduleArtifactsCommand extends Command
{
    protected $signature = 'schedule:repair-artifacts
                            {--day= : Der halb beobachtete Tag (Vorgabe: letzter Tag des Feed-Fensters)}
                            {--dry-run : Nur berichten, nichts schreiben}';

    protected $description = 'Beobachtungen vom halb gesehenen Fensterrand zurücknehmen und unbegründete Periodenwechsel-Vorschläge löschen';

    public function handle(ScheduleRepairService $repair): int
    {
        $probelauf = (bool) $this->option('dry-run');
        $tag = $this->option('day');

        if ($tag !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $tag) !== 1) {
            $this->error('--day erwartet ein Datum als JJJJ-MM-TT.');

            return self::FAILURE;
        }

        $bericht = $repair->repair($tag === null ? null : (string) $tag, $probelauf);

        if ($bericht['day'] === null) {
            $this->warn('Kein Feed-Fenster vorhanden — der Fensterrand wird übersprungen.');
        }

        $this->table(
            ['Kennzahl', 'Wert'],
            [
                ['Halb beobachteter Tag', $bericht['day'] ?? '—'],
                ['Intervalle entfernt', $bericht['intervals_removed']],
                ['Versionen entfernt', $bericht['versions_removed']],
                ['Grenzen wieder geöffnet', $bericht['boundaries_reopened']],
                ['Vorschläge zurückgezogen', implode(', ', $bericht['offers_withdrawn']) ?: '—'],
            ],
        );

        if ($probelauf) {
            $this->warn('Probelauf — es wurde nichts geschrieben.');

            return self::SUCCESS;
        }

        $this->info(
            $bericht['intervals_removed'] === 0 && $bericht['offers_withdrawn'] === []
                ? 'Nichts zu tun — der Bestand ist bereits bereinigt.'
                : 'Bereinigung abgeschlossen.',
        );

        return self::SUCCESS;
    }
}
