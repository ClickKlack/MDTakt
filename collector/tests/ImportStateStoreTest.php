<?php

declare(strict_types=1);

namespace MdTakt\Collector\Tests;

use MdTakt\Collector\Services\ImportStateStore;
use PHPUnit\Framework\TestCase;

final class ImportStateStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/mdtakt-state-' . uniqid() . '/last-import.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
            @rmdir(dirname($this->path));
        }
    }

    public function test_read_returns_null_when_no_state_exists(): void
    {
        $store = new ImportStateStore($this->path);

        $this->assertNull($store->read());
    }

    public function test_write_then_read_round_trips_state(): void
    {
        $store = new ImportStateStore($this->path);
        $state = [
            'etag' => '"eec81cc-654a"',
            'last_modified' => 'Sat, 20 Jun 2026 04:01:10 GMT',
            'sha256' => str_repeat('a', 64),
            'feed_version' => 'latest-nv-free',
            'imported_at' => '2026-06-22T10:00:00Z',
        ];

        $store->write($state);

        // Verzeichnis wird bei Bedarf angelegt.
        $this->assertFileExists($this->path);
        $this->assertSame($state, $store->read());
    }
}
