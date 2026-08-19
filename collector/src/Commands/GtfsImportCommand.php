<?php

declare(strict_types=1);

namespace MdTakt\Collector\Commands;

use GuzzleHttp\Client;
use MdTakt\Collector\Http\EngineClient;
use MdTakt\Collector\Services\FeedArchiveService;
use MdTakt\Collector\Services\GtfsFeedService;
use MdTakt\Collector\Services\ImportStateStore;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * CLI-Einstiegspunkt: lädt den GTFS-Feed, filtert auf MVB-Tram und pusht ihn an die Engine.
 *
 * Konfiguration über Umgebungsvariablen:
 *   GTFS_FEED_URL, GTFS_AGENCY_FILTER, ENGINE_BASE_URL, COLLECTOR_API_TOKEN, COLLECTOR_LOG_PATH
 */
final class GtfsImportCommand extends Command
{
    protected static $defaultName = 'collector:import-gtfs';

    protected function configure(): void
    {
        $this->setName('collector:import-gtfs')
            ->setDescription('GTFS-Feed laden, auf Magdeburger Tram filtern und an die Engine importieren')
            ->addOption('feed-url', null, InputOption::VALUE_REQUIRED, 'GTFS-Feed-URL (override)', null)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Import erzwingen, auch wenn der Feed unverändert ist')
            ->addOption('keep-temp', null, InputOption::VALUE_NONE, 'Temporäre Dateien nicht löschen')
            ->addOption('zip', null, InputOption::VALUE_REQUIRED, 'Statt Download eine vorhandene GTFS-ZIP importieren (z. B. aus dem Archiv)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = $this->makeLogger($output);

        $feedUrl = (string) ($input->getOption('feed-url') ?? $this->env('GTFS_FEED_URL', 'https://download.gtfs.de/germany/nv_free/latest.zip'));
        $agencyFilter = $this->env('GTFS_AGENCY_FILTER', 'Magdeburger Verkehrsbetriebe');
        $baseUrl = $this->env('ENGINE_BASE_URL', 'http://localhost:8000');
        $token = $this->env('COLLECTOR_API_TOKEN', '');

        if ($token === '') {
            $logger->error('COLLECTOR_API_TOKEN is not set');

            return Command::FAILURE;
        }

        $tmpDir = sys_get_temp_dir() . '/mdtakt-gtfs-' . getmypid();
        $zipPath = $tmpDir . '.zip';
        $extractDir = $tmpDir . '-extracted';

        $force = (bool) $input->getOption('force');
        $state = new ImportStateStore($this->env('COLLECTOR_STATE_PATH', $this->defaultStatePath()));
        $previous = $force ? null : $state->read();

        $feed = new GtfsFeedService($logger, $agencyFilter);
        $engine = new EngineClient(new Client(), $logger, $baseUrl, $token, (int) $this->env('ENGINE_TIMEOUT_SECONDS', '300'));

        $lokaleZip = $input->getOption('zip');

        try {
            if ($lokaleZip !== null) {
                // Import aus einer bereits vorliegenden ZIP — für Wiederherstellung nach einem
                // abgebrochenen Lauf und (später) für die rückwirkende Konsolidierung aus dem Archiv.
                if (! is_file((string) $lokaleZip)) {
                    $logger->error('GTFS zip not found', ['path' => $lokaleZip]);
                    $output->writeln('<error>ZIP nicht gefunden: ' . $lokaleZip . '</error>');

                    return Command::FAILURE;
                }

                $zipPath = (string) $lokaleZip;
                $logger->info('Importing from local GTFS zip', ['path' => $zipPath]);

                $feed->extract($zipPath, $extractDir);
                $base = $feed->parseBaseTables($extractDir);
                $tripIds = $base['trip_ids'];
                unset($base['trip_ids']);

                $result = $engine->importGtfs($base, $feed->streamStopTimes($extractDir, $tripIds));
                $imported = $result['data']['imported'] ?? [];
                $logger->info('GTFS import via engine completed', is_array($imported) ? $imported : []);

                // Kein State-Update: Die ZIP kann ein älterer Archivstand sein — sonst hielte
                // der Collector ihn faelschlich fuer den zuletzt geladenen Feed.
                $output->writeln('<info>GTFS-Import aus lokaler ZIP abgeschlossen (State unveraendert).</info>');

                return Command::SUCCESS;
            }

            $meta = $feed->downloadConditional($feedUrl, $zipPath, $previous);

            // Vor dem Download abgebrochen: Server meldet "nicht geändert".
            if ($meta['status'] === 304) {
                $logger->info('GTFS feed unchanged since last import, skipping', ['etag' => $meta['etag']]);
                $output->writeln('<info>Feed unverändert — Import übersprungen.</info>');

                return Command::SUCCESS;
            }

            // Nach dem Download: Inhalt identisch (ETag geändert, sha256 gleich)?
            if (! $force && $previous !== null && ($previous['sha256'] ?? null) !== null && $previous['sha256'] === $meta['sha256']) {
                $logger->info('GTFS feed content identical to last import, skipping', ['sha256' => $meta['sha256']]);
                $output->writeln('<info>Feed inhaltlich unverändert — Import übersprungen.</info>');

                return Command::SUCCESS;
            }

            // Archivieren, sobald der Inhalt als neu erkannt ist — unabhängig davon, ob der
            // Import danach durchläuft. Das Archiv soll den Feed retten, nicht den Lauf.
            $archivePath = $this->env('GTFS_ARCHIVE_PATH', $this->defaultArchivePath());
            $archive = new FeedArchiveService($logger, strtolower($archivePath) === 'off' ? '' : $archivePath);
            // feed_version steckt in feed_info.txt und ist hier noch nicht geparst — der
            // Dateiname traegt Datum und sha256, das genuegt zur Identifikation.
            $archive->store($zipPath, $meta['sha256']);

            $feed->extract($zipPath, $extractDir);

            // Basistabellen parsen (klein), stop_times separat strömen (speicherschonend).
            $base = $feed->parseBaseTables($extractDir);
            $tripIds = $base['trip_ids'];
            unset($base['trip_ids']);

            $result = $engine->importGtfs($base, $feed->streamStopTimes($extractDir, $tripIds));

            $imported = $result['data']['imported'] ?? [];
            $logger->info('GTFS import via engine completed', is_array($imported) ? $imported : []);

            // Erfolgreichen Stand merken — erst NACH erfolgreichem Import.
            $state->write([
                'etag'          => $meta['etag'],
                'last_modified' => $meta['last_modified'],
                'sha256'        => $meta['sha256'],
                'feed_version'  => $base['feed_info']['feed_version'] ?? null,
                'imported_at'   => gmdate('Y-m-d\TH:i:s\Z'),
            ]);

            $output->writeln('<info>GTFS-Import abgeschlossen.</info>');

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $logger->error('GTFS import command failed', ['message' => $e->getMessage()]);
            $output->writeln('<error>GTFS-Import fehlgeschlagen: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        } finally {
            if (! $input->getOption('keep-temp')) {
                // Eine uebergebene ZIP gehoert dem Aufrufer (Archiv!) — nur das Extrakt aufraeumen.
                $this->cleanup($lokaleZip === null ? $zipPath : null, $extractDir);
            }
        }
    }

    private function makeLogger(OutputInterface $output): Logger
    {
        $logger = new Logger('collector');
        // Strukturierte Logs auf stdout + tägliche Logdatei (siehe CLAUDE.md).
        $logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));

        // Tägliche Logdatei ist Default — COLLECTOR_LOG_PATH überschreibt nur den Ort
        // (z.B. /var/log/mdtakt/ auf dem NAS). 14 Tage Aufbewahrung.
        $logPath = $this->env('COLLECTOR_LOG_PATH', $this->defaultLogPath());
        $this->ensureLogDir($logPath);
        $logger->pushHandler(new RotatingFileHandler($logPath, 14, Level::Info));

        return $logger;
    }

    private function defaultLogPath(): string
    {
        // Collector-Wurzel: src/Commands → zwei Ebenen hoch.
        return dirname(__DIR__, 2) . '/storage/logs/collector.log';
    }

    private function defaultStatePath(): string
    {
        return dirname(__DIR__, 2) . '/storage/state/last-import.json';
    }

    private function defaultArchivePath(): string
    {
        return dirname(__DIR__, 2) . '/storage/archive';
    }

    private function ensureLogDir(string $logPath): void
    {
        $dir = dirname($logPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private function cleanup(?string $zipPath, string $extractDir): void
    {
        if ($zipPath !== null && is_file($zipPath)) {
            @unlink($zipPath);
        }

        if (is_dir($extractDir)) {
            foreach (glob($extractDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($extractDir);
        }
    }

    private function env(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return ($value === false || $value === null || $value === '') ? $default : (string) $value;
    }
}
