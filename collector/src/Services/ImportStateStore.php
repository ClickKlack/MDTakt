<?php

declare(strict_types=1);

namespace MdTakt\Collector\Services;

/**
 * Persistiert den Zustand des letzten erfolgreichen GTFS-Imports als lokale JSON-Datei.
 *
 * Damit erkennt der Collector beim nächsten Lauf, ob sich der Feed geändert hat
 * (ETag/Last-Modified für den Conditional-Request, sha256 als inhaltliche Gewissheit).
 */
final class ImportStateStore
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return array{etag?: ?string, last_modified?: ?string, sha256?: ?string, feed_version?: ?string, imported_at?: ?string}|null
     */
    public function read(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($this->path), true);

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function write(array $state): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return;
        }

        file_put_contents(
            $this->path,
            (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    public function path(): string
    {
        return $this->path;
    }
}
