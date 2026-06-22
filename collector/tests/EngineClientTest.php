<?php

declare(strict_types=1);

namespace MdTakt\Collector\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MdTakt\Collector\Http\EngineClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class EngineClientTest extends TestCase
{
    /** @var array<int, array{request: Request}> */
    private array $history = [];

    private function client(MockHandler $mock): EngineClient
    {
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        $http = new Client(['handler' => $stack]);

        return new EngineClient($http, new NullLogger(), 'http://engine.test', 'secret-token');
    }

    /**
     * @return array<string, mixed>
     */
    private function batch(int $stopTimesCount): array
    {
        $stopTimes = [];
        for ($i = 1; $i <= $stopTimesCount; $i++) {
            $stopTimes[] = ['trip_id' => 'T1', 'stop_id' => 'S1', 'arrival_time' => '05:30:00', 'departure_time' => '05:30:00', 'stop_sequence' => $i];
        }

        return [
            'routes'         => [['route_id' => '1', 'route_short_name' => '1', 'route_type' => 0]],
            'stops'          => [['stop_id' => 'S1', 'stop_name' => 'Hbf', 'lat' => 52.0, 'lon' => 11.0]],
            'trips'          => [['trip_id' => 'T1', 'route_id' => '1', 'service_id' => 'W1', 'block_id' => null, 'direction_id' => 0]],
            'calendar_dates' => [['service_id' => 'W1', 'date' => '2026-06-22', 'exception_type' => 1]],
            'stop_times'     => $stopTimes,
            'feed_info'      => ['feed_version' => 'v1', 'feed_start_date' => null, 'feed_end_date' => null],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeGzipBody(Request $request): array
    {
        $raw = (string) $request->getBody();
        $json = gzdecode($raw);
        $this->assertNotFalse($json, 'Request body is not gzip-encoded');

        return (array) json_decode($json, true);
    }

    public function test_import_starts_chunks_stop_times_and_finishes(): void
    {
        // 25.000 stop_times → 3 Chunks (10k, 10k, 5k).
        $mock = new MockHandler([
            new Response(201, [], (string) json_encode(['data' => ['run_id' => 42, 'status' => 'running', 'imported' => []]])),
            new Response(200, [], (string) json_encode(['data' => ['run_id' => 42, 'status' => 'running', 'imported' => ['stop_times' => 10000]]])),
            new Response(200, [], (string) json_encode(['data' => ['run_id' => 42, 'status' => 'running', 'imported' => ['stop_times' => 20000]]])),
            new Response(200, [], (string) json_encode(['data' => ['run_id' => 42, 'status' => 'running', 'imported' => ['stop_times' => 25000]]])),
            new Response(200, [], (string) json_encode(['data' => ['run_id' => 42, 'status' => 'success', 'imported' => ['stop_times' => 25000]]])),
        ]);

        $client = $this->client($mock);
        $batch = $this->batch(25000);
        $result = $client->importGtfs($batch, $batch['stop_times']);

        $this->assertSame('success', $result['data']['status']);

        // 1 Start + 3 Chunks + 1 Finish.
        $this->assertCount(5, $this->history);

        $paths = array_map(static fn ($h): string => $h['request']->getUri()->getPath(), $this->history);
        $this->assertSame('/api/v1/collector/imports', $paths[0]);
        $this->assertSame('/api/v1/collector/imports/42/stop-times', $paths[1]);
        $this->assertSame('/api/v1/collector/imports/42/stop-times', $paths[2]);
        $this->assertSame('/api/v1/collector/imports/42/stop-times', $paths[3]);
        $this->assertSame('/api/v1/collector/imports/42/finish', $paths[4]);

        // Jeder Request ist gzip-komprimiert und token-authentisiert.
        foreach ($this->history as $entry) {
            $this->assertSame('gzip', $entry['request']->getHeaderLine('Content-Encoding'));
            $this->assertSame('Bearer secret-token', $entry['request']->getHeaderLine('Authorization'));
        }

        // Start enthält die Basistabellen, aber KEINE stop_times.
        $startBody = $this->decodeGzipBody($this->history[0]['request']);
        $this->assertArrayHasKey('routes', $startBody);
        $this->assertArrayNotHasKey('stop_times', $startBody);

        // Chunk-Größen 10k / 10k / 5k.
        $this->assertCount(10000, $this->decodeGzipBody($this->history[1]['request'])['stop_times']);
        $this->assertCount(10000, $this->decodeGzipBody($this->history[2]['request'])['stop_times']);
        $this->assertCount(5000, $this->decodeGzipBody($this->history[3]['request'])['stop_times']);
    }

    public function test_error_response_throws_with_engine_message(): void
    {
        $mock = new MockHandler([
            new Response(422, [], (string) json_encode(['error' => ['code' => 422, 'message' => 'routes field is required.']])),
        ]);

        $client = $this->client($mock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('routes field is required.');

        $batch = $this->batch(5);
        $client->importGtfs($batch, $batch['stop_times']);
    }

    public function test_missing_run_id_throws(): void
    {
        $mock = new MockHandler([
            new Response(201, [], (string) json_encode(['data' => ['status' => 'running']])),
        ]);

        $client = $this->client($mock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('run_id');

        $batch = $this->batch(5);
        $client->importGtfs($batch, $batch['stop_times']);
    }
}
