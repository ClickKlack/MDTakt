<?php

declare(strict_types=1);

namespace MdTakt\Collector\Tests;

use MdTakt\Collector\Services\FeedArchiveService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FeedArchiveServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mdtakt-archive-test-' . getmypid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        @unlink($this->quelle());
    }

    private function quelle(): string
    {
        return sys_get_temp_dir() . '/mdtakt-archive-src-' . getmypid() . '.zip';
    }

    private function zipAnlegen(string $inhalt = 'feed'): string
    {
        file_put_contents($this->quelle(), $inhalt);

        return $this->quelle();
    }

    public function test_stores_zip_with_date_and_hash_in_name(): void
    {
        $service = new FeedArchiveService(new NullLogger(), $this->dir);

        $pfad = $service->store($this->zipAnlegen(), str_repeat('a', 64));

        $this->assertNotNull($pfad);
        $this->assertFileExists($pfad);
        $this->assertStringContainsString(gmdate('Y-m-d'), basename($pfad));
        $this->assertStringContainsString('aaaaaaaaaaaa', basename($pfad));
        $this->assertSame('feed', file_get_contents($pfad));
    }

    public function test_creates_the_archive_directory_if_missing(): void
    {
        $this->assertDirectoryDoesNotExist($this->dir);

        (new FeedArchiveService(new NullLogger(), $this->dir))->store($this->zipAnlegen(), str_repeat('b', 64));

        $this->assertDirectoryExists($this->dir);
    }

    public function test_same_content_on_the_same_day_is_not_stored_twice(): void
    {
        $service = new FeedArchiveService(new NullLogger(), $this->dir);
        $hash = str_repeat('c', 64);

        $erst = $service->store($this->zipAnlegen('erster'), $hash);
        $zweit = $service->store($this->zipAnlegen('zweiter'), $hash);

        $this->assertSame($erst, $zweit);
        $this->assertCount(1, glob($this->dir . '/*.zip') ?: []);
        // Der erste Inhalt bleibt erhalten — kein stilles Ueberschreiben.
        $this->assertSame('erster', file_get_contents($erst));
    }

    public function test_empty_path_disables_archiving(): void
    {
        $service = new FeedArchiveService(new NullLogger(), '');

        $this->assertNull($service->store($this->zipAnlegen(), str_repeat('d', 64)));
    }
}
