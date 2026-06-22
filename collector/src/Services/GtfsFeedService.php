<?php

declare(strict_types=1);

namespace MdTakt\Collector\Services;

use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Lädt den GTFS-Feed, entpackt ihn und filtert die Daten auf die MVB-Agency.
 *
 * Eingrenzung allein über die Agency (Substring auf agency_name, Fallback agency_id) —
 * alle Verkehrsmittel der MVB (Tram + Bus), nicht nur Straßenbahn. Der echte
 * route_type wird übernommen.
 *
 * Das Ergebnis ist konsistent: nur Trips der behaltenen Routen, nur StopTimes dieser
 * Trips, nur referenzierte Stops und nur Calendar-Dates der genutzten Services.
 */
final class GtfsFeedService
{
    private readonly ClientInterface $http;

    /**
     * @param  string  $agencyFilter  Substring-Filter auf agency_name/agency_id; leer = alle Agencies
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $agencyFilter = '',
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client();
    }

    /**
     * Lädt das GTFS-ZIP — aber nur, wenn es sich seit dem letzten Import geändert hat.
     *
     * Schickt einen Conditional GET (If-None-Match / If-Modified-Since) anhand des
     * vorherigen State. Antwortet der Server mit 304, wird nichts geladen. Sonst wird
     * streamend auf die Platte geschrieben (sink) und der sha256 gebildet.
     *
     * @param  array<string, mixed>|null  $previous  letzter State (etag/last_modified/sha256)
     * @return array{status: int, etag: ?string, last_modified: ?string, sha256: ?string}
     */
    public function downloadConditional(string $url, string $destZip, ?array $previous): array
    {
        $headers = [];
        if (! empty($previous['etag'])) {
            $headers['If-None-Match'] = (string) $previous['etag'];
        }
        if (! empty($previous['last_modified'])) {
            $headers['If-Modified-Since'] = (string) $previous['last_modified'];
        }

        $this->logger->info('GTFS download started', ['url' => $url, 'conditional' => $headers !== []]);

        try {
            $response = $this->http->request('GET', $url, [
                'headers'         => $headers,
                'sink'            => $destZip,
                'http_errors'     => false,
                'connect_timeout' => 30,
                'timeout'         => 600,
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException("GTFS feed not reachable: {$url}", previous: $e);
        }

        $status = $response->getStatusCode();
        $etag = $response->getHeaderLine('ETag') ?: null;
        $lastModified = $response->getHeaderLine('Last-Modified') ?: null;

        if ($status === 304) {
            $this->logger->info('GTFS feed not modified (304)', ['url' => $url]);

            return [
                'status'        => 304,
                'etag'          => $previous['etag'] ?? $etag,
                'last_modified' => $previous['last_modified'] ?? $lastModified,
                'sha256'        => $previous['sha256'] ?? null,
            ];
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("GTFS feed download failed: HTTP {$status}");
        }

        $bytes = is_file($destZip) ? (int) filesize($destZip) : 0;
        $sha256 = $bytes > 0 ? hash_file('sha256', $destZip) : null;

        $this->logger->info('GTFS download finished', ['bytes' => $bytes]);

        return ['status' => $status, 'etag' => $etag, 'last_modified' => $lastModified, 'sha256' => $sha256];
    }

    /**
     * Entpackt das GTFS-ZIP in ein Zielverzeichnis und gibt dieses zurück.
     */
    public function extract(string $zipPath, string $destDir): string
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Could not open GTFS archive: {$zipPath}");
        }

        if (! is_dir($destDir) && ! mkdir($destDir, 0775, true) && ! is_dir($destDir)) {
            throw new RuntimeException("Could not create extract dir: {$destDir}");
        }

        $zip->extractTo($destDir);
        $zip->close();

        return $destDir;
    }

    /**
     * Liest die Basistabellen eines entpackten GTFS-Verzeichnisses (alles außer stop_times).
     *
     * stop_times werden separat per streamStopTimes() geströmt, damit der bundesweite
     * Feed nicht komplett in den Speicher muss. Diese Methode hält nur die kleineren
     * Tabellen plus die Menge der behaltenen Trip-IDs (für das Streaming).
     *
     * @return array{
     *     routes: array<int, array<string, mixed>>,
     *     stops: array<int, array<string, mixed>>,
     *     trips: array<int, array<string, mixed>>,
     *     calendar_dates: array<int, array<string, mixed>>,
     *     feed_info: array<string, string|null>,
     *     trip_ids: array<string, true>
     * }
     */
    public function parseBaseTables(string $dir): array
    {
        $agencyNameById = $this->loadAgencyNames($dir);

        // 1) Routen auf die MVB-Agency filtern — alle Verkehrsmittel (Tram + Bus + …).
        $routes = [];
        $keptRouteIds = [];
        foreach ($this->readCsv($dir . '/routes.txt') as $row) {
            if (! $this->agencyMatches($row['agency_id'] ?? '', $agencyNameById)) {
                continue;
            }

            $routeId = $row['route_id'];
            $keptRouteIds[$routeId] = true;
            $routes[] = [
                'route_id'         => $routeId,
                'route_short_name' => $row['route_short_name'] ?? '',
                'route_type'       => (int) ($row['route_type'] ?? 0),
            ];
        }

        if ($this->agencyFilter !== '' && $routes === []) {
            $this->logger->warning('Agency filter matched no routes', ['filter' => $this->agencyFilter]);
        }

        // 2) Trips der behaltenen Routen.
        $trips = [];
        $keptTripIds = [];
        $keptServiceIds = [];
        foreach ($this->readCsv($dir . '/trips.txt') as $row) {
            if (! isset($keptRouteIds[$row['route_id'] ?? ''])) {
                continue;
            }

            $tripId = $row['trip_id'];
            $keptTripIds[$tripId] = true;
            $keptServiceIds[$row['service_id']] = true;
            $trips[] = [
                'trip_id'      => $tripId,
                'route_id'     => $row['route_id'],
                'service_id'   => $row['service_id'],
                'block_id'     => $this->nullIfEmpty($row['block_id'] ?? ''),
                'direction_id' => isset($row['direction_id']) && $row['direction_id'] !== ''
                    ? (int) $row['direction_id']
                    : null,
            ];
        }

        // 3) Erster Durchlauf über stop_times: NUR die referenzierten Stop-IDs sammeln
        //    (die Zeilen selbst werden später geströmt, nicht im Speicher gehalten).
        $keptStopIds = [];
        foreach ($this->readCsv($dir . '/stop_times.txt') as $row) {
            if (isset($keptTripIds[$row['trip_id'] ?? ''])) {
                $keptStopIds[$row['stop_id']] = true;
            }
        }

        // 4) Nur referenzierte Stops.
        $stops = [];
        foreach ($this->readCsv($dir . '/stops.txt') as $row) {
            if (! isset($keptStopIds[$row['stop_id'] ?? ''])) {
                continue;
            }

            $stops[] = [
                'stop_id'   => $row['stop_id'],
                'stop_name' => $row['stop_name'] ?? '',
                'lat'       => isset($row['stop_lat']) && $row['stop_lat'] !== '' ? (float) $row['stop_lat'] : null,
                'lon'       => isset($row['stop_lon']) && $row['stop_lon'] !== '' ? (float) $row['stop_lon'] : null,
            ];
        }

        // 5) calendar.txt — reguläres Wochenmuster der genutzten Services (optional).
        $calendar = [];
        if (is_file($dir . '/calendar.txt')) {
            foreach ($this->readCsv($dir . '/calendar.txt') as $row) {
                if (! isset($keptServiceIds[$row['service_id'] ?? ''])) {
                    continue;
                }

                $calendar[] = [
                    'service_id' => $row['service_id'],
                    'monday'     => (int) ($row['monday'] ?? 0),
                    'tuesday'    => (int) ($row['tuesday'] ?? 0),
                    'wednesday'  => (int) ($row['wednesday'] ?? 0),
                    'thursday'   => (int) ($row['thursday'] ?? 0),
                    'friday'     => (int) ($row['friday'] ?? 0),
                    'saturday'   => (int) ($row['saturday'] ?? 0),
                    'sunday'     => (int) ($row['sunday'] ?? 0),
                    'start_date' => $this->normalizeGtfsDate($row['start_date'] ?? ''),
                    'end_date'   => $this->normalizeGtfsDate($row['end_date'] ?? ''),
                ];
            }
        }

        // 6) calendar_dates.txt — Ausnahmen der genutzten Services.
        $calendarDates = [];
        foreach ($this->readCsv($dir . '/calendar_dates.txt') as $row) {
            if (! isset($keptServiceIds[$row['service_id'] ?? ''])) {
                continue;
            }

            $calendarDates[] = [
                'service_id'     => $row['service_id'],
                'date'           => $this->normalizeGtfsDate($row['date']),
                'exception_type' => (int) $row['exception_type'],
            ];
        }

        $feedInfo = $this->loadFeedInfo($dir);

        $this->logger->info('GTFS base tables parsed', [
            'routes'         => count($routes),
            'stops'          => count($stops),
            'trips'          => count($trips),
            'calendar'       => count($calendar),
            'calendar_dates' => count($calendarDates),
            'feed_version'   => $feedInfo['feed_version'] ?? null,
        ]);

        return [
            'routes'         => $routes,
            'stops'          => $stops,
            'trips'          => $trips,
            'calendar'       => $calendar,
            'calendar_dates' => $calendarDates,
            'feed_info'      => $feedInfo,
            'trip_ids'       => $keptTripIds,
        ];
    }

    /**
     * Strömt die normalisierten stop_times der behaltenen Trips — zeilenweise,
     * ohne sie als Ganzes im Speicher zu halten.
     *
     * @param  array<string, true>  $tripIds  Menge behaltener trip_id (aus parseBaseTables)
     * @return Generator<int, array<string, mixed>>
     */
    public function streamStopTimes(string $dir, array $tripIds): Generator
    {
        foreach ($this->readCsv($dir . '/stop_times.txt') as $row) {
            if (! isset($tripIds[$row['trip_id'] ?? ''])) {
                continue;
            }

            yield [
                'trip_id'        => $row['trip_id'],
                'stop_id'        => $row['stop_id'],
                'arrival_time'   => $this->normalizeGtfsTime($row['arrival_time'] ?? null),
                'departure_time' => $this->normalizeGtfsTime($row['departure_time'] ?? null),
                'stop_sequence'  => (int) $row['stop_sequence'],
            ];
        }
    }

    /**
     * Bequemer Wrapper: Basistabellen + materialisierte stop_times als ein Batch.
     * NUR für Tests/kleine Feeds — der Command nutzt parseBaseTables() + streamStopTimes().
     *
     * @return array<string, mixed>
     */
    public function parseDirectory(string $dir): array
    {
        $base = $this->parseBaseTables($dir);
        $tripIds = $base['trip_ids'];
        unset($base['trip_ids']);
        $base['stop_times'] = iterator_to_array($this->streamStopTimes($dir, $tripIds), false);

        return $base;
    }

    /**
     * Liest den Feed-Datenstand aus feed_info.txt (optional, eine Datenzeile).
     *
     * @return array<string, string|null>
     */
    private function loadFeedInfo(string $dir): array
    {
        $path = $dir . '/feed_info.txt';

        if (! is_file($path)) {
            return [];
        }

        // Generator → erste Datenzeile genügt (feed_info.txt hat genau eine).
        $row = [];
        foreach ($this->readCsv($path) as $first) {
            $row = $first;
            break;
        }

        return [
            'feed_version'    => $this->nullIfEmpty($row['feed_version'] ?? ''),
            'feed_start_date' => isset($row['feed_start_date']) && $row['feed_start_date'] !== ''
                ? $this->normalizeGtfsDate($row['feed_start_date'])
                : null,
            'feed_end_date'   => isset($row['feed_end_date']) && $row['feed_end_date'] !== ''
                ? $this->normalizeGtfsDate($row['feed_end_date'])
                : null,
        ];
    }

    /**
     * Normalisiert eine GTFS-Zeit auf HH:MM:SS. Werte > 24:00:00 (Fahrten nach
     * Mitternacht) bleiben erhalten, da sie laut Schema als String gespeichert werden.
     */
    public function normalizeGtfsTime(?string $time): ?string
    {
        if ($time === null || trim($time) === '') {
            return null;
        }

        $parts = explode(':', trim($time));

        if (count($parts) !== 3) {
            throw new InvalidArgumentException("Invalid GTFS time: {$time}");
        }

        return sprintf('%02d:%02d:%02d', (int) $parts[0], (int) $parts[1], (int) $parts[2]);
    }

    /**
     * GTFS-Datum (YYYYMMDD) → ISO-Datum (YYYY-MM-DD).
     */
    private function normalizeGtfsDate(string $date): string
    {
        $date = trim($date);

        if (preg_match('/^\d{8}$/', $date) === 1) {
            return substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        }

        // Bereits ISO oder unbekannt — unverändert durchreichen.
        return $date;
    }

    /**
     * @param  array<string, string>  $agencyNameById
     */
    private function agencyMatches(string $agencyId, array $agencyNameById): bool
    {
        if ($this->agencyFilter === '') {
            return true;
        }

        $needle = mb_strtolower($this->agencyFilter);
        $name = mb_strtolower($agencyNameById[$agencyId] ?? '');

        return str_contains($name, $needle) || mb_strtolower($agencyId) === $needle;
    }

    /**
     * @return array<string, string>  agency_id → agency_name
     */
    private function loadAgencyNames(string $dir): array
    {
        $path = $dir . '/agency.txt';

        if (! is_file($path)) {
            return [];
        }

        $map = [];
        foreach ($this->readCsv($path) as $row) {
            $map[$row['agency_id'] ?? ''] = $row['agency_name'] ?? '';
        }

        return $map;
    }

    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * Liest eine GTFS-CSV zeilenweise als Generator (Header → Wert).
     * Entfernt ein evtl. vorhandenes UTF-8-BOM aus dem ersten Header.
     *
     * Bewusst ein Generator statt Array: Dateien des bundesweiten Feeds
     * (z.B. stop_times.txt mit Millionen Zeilen) dürfen nicht komplett in den
     * Speicher — gefiltert wird strömend beim Durchlaufen.
     *
     * @return Generator<int, array<string, string>>
     */
    private function readCsv(string $path): Generator
    {
        if (! is_file($path)) {
            throw new RuntimeException("GTFS file missing: {$path}");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Could not open GTFS file: {$path}");
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '\\');
            if ($header === false) {
                return;
            }

            // BOM aus erster Spalte entfernen.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(static fn ($h): string => trim((string) $h), $header);

            while (($record = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                // Leerzeilen überspringen.
                if ($record === [null] || $record === []) {
                    continue;
                }

                $row = [];
                foreach ($header as $i => $key) {
                    $row[$key] = isset($record[$i]) ? (string) $record[$i] : '';
                }

                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }
}
