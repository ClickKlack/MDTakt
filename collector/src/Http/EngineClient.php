<?php

declare(strict_types=1);

namespace MdTakt\Collector\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * HTTP-Client für die Engine-API. Importiert GTFS-Daten als gechunkten Lebenszyklus
 * (Lauf starten → stop_times-Chunks → abschließen) und komprimiert jeden Body mit gzip,
 * damit große Payloads unter PHPs post_max_size bleiben.
 */
final class EngineClient
{
    /**
     * Zeilen pro stop_times-Request. Bei ~120 Bytes/Zeile bleibt ein Chunk (gzip)
     * weit unter den üblichen Limits.
     */
    private const STOP_TIMES_CHUNK = 10000;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
        private readonly string $token,
    ) {
    }

    /**
     * Führt den kompletten Import durch und gibt die Engine-Antwort des Abschlusses zurück.
     *
     * stop_times werden als iterierbarer Strom übergeben und in Chunks gesendet — es wird
     * immer nur ein Chunk gepuffert, nie der gesamte Datensatz im Speicher gehalten.
     *
     * @param  array<string, mixed>  $base  routes/stops/trips/calendar_dates/feed_info
     * @param  iterable<array<string, mixed>>  $stopTimes  Strom der stop_times-Zeilen
     * @return array<string, mixed>
     */
    public function importGtfs(array $base, iterable $stopTimes): array
    {
        $start = $this->send('POST', '/api/v1/collector/imports', [
            'feed_info'      => $base['feed_info'] ?? [],
            'routes'         => $base['routes'] ?? [],
            'stops'          => $base['stops'] ?? [],
            'trips'          => $base['trips'] ?? [],
            'calendar'       => $base['calendar'] ?? [],
            'calendar_dates' => $base['calendar_dates'] ?? [],
        ]);

        $runId = $start['data']['run_id'] ?? null;
        if (! is_int($runId) && ! is_string($runId)) {
            throw new RuntimeException('Engine did not return a run_id on import start.');
        }

        $buffer = [];
        $chunks = 0;
        $total = 0;
        foreach ($stopTimes as $row) {
            $buffer[] = $row;
            if (count($buffer) >= self::STOP_TIMES_CHUNK) {
                $this->send('POST', "/api/v1/collector/imports/{$runId}/stop-times", ['stop_times' => $buffer]);
                $total += count($buffer);
                $chunks++;
                $buffer = [];
            }
        }
        if ($buffer !== []) {
            $this->send('POST', "/api/v1/collector/imports/{$runId}/stop-times", ['stop_times' => $buffer]);
            $total += count($buffer);
            $chunks++;
        }

        $this->logger->info('GTFS stop_times transmitted', [
            'run_id' => $runId,
            'chunks' => $chunks,
            'rows'   => $total,
        ]);

        return $this->send('POST', "/api/v1/collector/imports/{$runId}/finish", (object) []);
    }

    /**
     * Sendet einen gzip-komprimierten JSON-Request und validiert die Antwort.
     *
     * @param  array<string, mixed>|object  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array|object $payload): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $body = gzencode((string) json_encode($payload));

        try {
            $response = $this->http->request($method, $url, [
                'headers' => [
                    'Authorization'    => 'Bearer ' . $this->token,
                    'Accept'           => 'application/json',
                    'Content-Type'     => 'application/json',
                    'Content-Encoding' => 'gzip',
                ],
                'body'        => $body,
                'http_errors' => false, // Fehler selbst auswerten, um die Engine-Meldung zu loggen.
                'timeout'     => 120,
            ]);
        } catch (GuzzleException $e) {
            // Kein Token ins Log.
            $this->logger->error('Engine request failed', ['path' => $path, 'message' => $e->getMessage()]);

            throw new RuntimeException('Engine request failed: ' . $e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);

        if ($status < 200 || $status >= 300 || ! is_array($decoded) || isset($decoded['error'])) {
            $message = is_array($decoded) && isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : "HTTP {$status}";

            $this->logger->error('Engine rejected request', ['path' => $path, 'status' => $status, 'message' => $message]);

            throw new RuntimeException("Engine rejected {$path}: {$message}");
        }

        return $decoded;
    }
}
