<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Requests\SightingIngestRequest;
use App\Services\SightingIngestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Spielt einen Tracker-Export (derselbe Body wie `POST /collector/sightings`) aus einer Datei ein.
 *
 * Gedacht für die **Validierung am echten Bestand**, bevor der Tracker den Push baut: Mit
 * `--dry-run` läuft alles in einer Transaktion, die am Ende verworfen wird, und der Befehl meldet
 * nur die Trefferquote und die Laufwege ohne Treffer.
 */
final class IngestSightingsFileCommand extends Command
{
    protected $signature = 'sightings:ingest-file {path : JSON-Datei im Format des Eingangs} {--dry-run : nichts speichern, nur Trefferquote melden}';

    protected $description = 'Sichtungs-Export aus MDKursTracker einspielen oder probeweise zuordnen';

    public function handle(SightingIngestService $service): int
    {
        $pfad = (string) $this->argument('path');
        $inhalt = @file_get_contents($pfad);

        if ($inhalt === false) {
            $this->error("Datei nicht lesbar: {$pfad}");

            return self::FAILURE;
        }

        $daten = json_decode($inhalt, true);

        if (! is_array($daten)) {
            $this->error('Kein gültiges JSON.');

            return self::FAILURE;
        }

        $pruefung = Validator::make($daten, SightingIngestRequest::ruleSet(limited: false));

        if ($pruefung->fails()) {
            $this->error('Ungültiger Export: '.$pruefung->errors()->first());

            return self::FAILURE;
        }

        $daten = $pruefung->validated();
        $probe = (bool) $this->option('dry-run');

        DB::beginTransaction();

        try {
            $ergebnis = $service->ingest($daten['trips'], $daten['sightings']);
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $probe ? DB::rollBack() : DB::commit();

        $this->report($ergebnis, $daten['trips']);

        if ($probe) {
            $this->warn('Probelauf — nichts gespeichert.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $ergebnis
     * @param  array<int, array<string, mixed>>  $trips
     */
    private function report(array $ergebnis, array $trips): void
    {
        $ergebnisse = $ergebnis['results'];
        $gesamt = count($ergebnisse);
        $jeTreffer = array_count_values(array_map(static fn (array $r): string => $r['match'] ?? $r['outcome'], $ergebnisse));
        $jeStatus = array_count_values(array_filter(array_column($ergebnisse, 'status')));
        $mitFahrt = ($jeTreffer['matched'] ?? 0) + ($jeTreffer['matched_next_version'] ?? 0);

        $this->info(sprintf('%d Sichtungen, %d mit Fahrt (%.1f %%)', $gesamt, $mitFahrt, $gesamt > 0 ? 100 * $mitFahrt / $gesamt : 0));
        $this->table(['Zuordnung', 'Anzahl'], array_map(null, array_keys($jeTreffer), array_values($jeTreffer)));
        $this->table(['Status', 'Anzahl'], array_map(null, array_keys($jeStatus), array_values($jeStatus)));

        if ($ergebnis['unmatched_fingerprints'] !== []) {
            $linien = array_column($trips, 'line', 'schedule_fingerprint');
            $this->line('Laufwege ohne Treffer:');

            foreach ($ergebnis['unmatched_fingerprints'] as $fp) {
                $this->line(sprintf('  Linie %-4s %s', $linien[$fp] ?? '?', $fp));
            }
        }
    }
}
