<?php

declare(strict_types=1);

namespace MdTakt\Collector\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MdTakt\Collector\Services\GtfsFeedService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GtfsFeedDownloadTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function service(MockHandler $mock): GtfsFeedService
    {
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return new GtfsFeedService(new NullLogger(), '', new Client(['handler' => $stack]));
    }

    public function test_not_modified_sends_conditional_headers_and_skips_download(): void
    {
        $mock = new MockHandler([new Response(304, ['ETag' => '"abc"'])]);
        $dest = sys_get_temp_dir() . '/mdtakt-cond-' . uniqid() . '.zip';

        $previous = [
            'etag' => '"abc"',
            'last_modified' => 'Sat, 20 Jun 2026 04:01:10 GMT',
            'sha256' => 'deadbeef',
        ];
        $meta = $this->service($mock)->downloadConditional('http://feed.test/x.zip', $dest, $previous);

        $this->assertSame(304, $meta['status']);
        // Vorheriger State bleibt erhalten — nichts neu geladen.
        $this->assertSame('deadbeef', $meta['sha256']);

        $request = $this->history[0]['request'];
        $this->assertSame('"abc"', $request->getHeaderLine('If-None-Match'));
        $this->assertSame('Sat, 20 Jun 2026 04:01:10 GMT', $request->getHeaderLine('If-Modified-Since'));

        @unlink($dest);
    }

    public function test_changed_feed_downloads_and_computes_sha256(): void
    {
        $body = 'ZIP-CONTENT-BYTES';
        $mock = new MockHandler([
            new Response(200, ['ETag' => '"new"', 'Last-Modified' => 'Mon, 22 Jun 2026 10:00:00 GMT'], $body),
        ]);
        $dest = sys_get_temp_dir() . '/mdtakt-cond-' . uniqid() . '.zip';

        $meta = $this->service($mock)->downloadConditional('http://feed.test/x.zip', $dest, null);

        $this->assertSame(200, $meta['status']);
        $this->assertSame('"new"', $meta['etag']);
        $this->assertSame('Mon, 22 Jun 2026 10:00:00 GMT', $meta['last_modified']);
        $this->assertSame(hash('sha256', $body), $meta['sha256']);
        $this->assertSame($body, file_get_contents($dest));

        // Ohne vorherigen State werden keine Conditional-Header gesendet.
        $request = $this->history[0]['request'];
        $this->assertSame('', $request->getHeaderLine('If-None-Match'));

        @unlink($dest);
    }
}
