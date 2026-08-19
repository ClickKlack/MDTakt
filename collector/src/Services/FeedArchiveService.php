<?php

declare(strict_types=1);

namespace MdTakt\Collector\Services;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Legt jeden heruntergeladenen GTFS-Feed als ZIP ab.
 *
 * Hintergrund: Der Feed ist ein rollierendes Zeitfenster und der Engine-Import ersetzt den
 * Roh-Bestand vollständig. Die Konsolidierung (FAHRPLANPERIODEN Phase B) hält bisher nur
 * fest, DASS sich ein Fahrplan geändert hat — die Fahrten selbst gehen mit dem nächsten
 * Import verloren, solange Phase C fehlt.
 *
 * Das Archiv ist die Versicherung dagegen: Aus ihm lässt sich das Konsolidat später
 * **rückwirkend** aufbauen. Ohne Archiv ist jede Woche vor Phase C endgültig verloren.
 */
final class FeedArchiveService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $archiveDir,
    ) {}

    /**
     * Kopiert die ZIP ins Archiv. Der Dateiname trägt Datum und sha256-Präfix, damit
     * derselbe Inhalt nicht zweimal landet und die Herkunft nachvollziehbar bleibt.
     *
     * @return string|null Pfad der Archivdatei; null wenn das Archiv abgeschaltet ist
     */
    public function store(string $zipPath, string $sha256, ?string $feedVersion = null): ?string
    {
        if ($this->archiveDir === '') {
            return null;
        }

        if (! is_dir($this->archiveDir) && ! @mkdir($this->archiveDir, 0775, true) && ! is_dir($this->archiveDir)) {
            throw new RuntimeException('Archivverzeichnis nicht anlegbar: ' . $this->archiveDir);
        }

        $name = sprintf('gtfs-%s-%s.zip', gmdate('Y-m-d'), substr($sha256, 0, 12));
        $ziel = rtrim($this->archiveDir, '/') . '/' . $name;

        // Gleicher Inhalt am selben Tag → nichts zu tun.
        if (is_file($ziel)) {
            $this->logger->info('GTFS feed already archived', ['path' => $ziel]);

            return $ziel;
        }

        if (! @copy($zipPath, $ziel)) {
            throw new RuntimeException('Archivierung fehlgeschlagen: ' . $ziel);
        }

        $this->logger->info('GTFS feed archived', [
            'path' => $ziel,
            'bytes' => (int) filesize($ziel),
            'sha256' => $sha256,
            'feed_version' => $feedVersion,
        ]);

        return $ziel;
    }
}
