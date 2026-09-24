<?php

declare(strict_types=1);

namespace Dply\Laravel;

use Illuminate\Support\Facades\Http;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;

/**
 * Flysystem disk for an attached dply bucket. Called from
 * DplyServiceProvider when Storage uses the dply driver. No data file.
 *
 * User: "it would inject and allow laravel and other apps to attach to the
 * storage like laravel already can with s3 or something else"
 */
final class DplyAdapter implements FilesystemAdapter
{
    public function __construct(private readonly string $host) {}

    public function fileExists(string $path): bool
    {
        return Http::timeout(15)->get($this->url($path))->successful();
    }

    public function directoryExists(string $path): bool
    {
        return $path === '' || $path === '/';
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $type = $config->get('mimetype', 'application/octet-stream');
        $response = Http::timeout(30)->withBody($contents, is_string($type) ? $type : 'application/octet-stream')->put($this->url($path));
        if (! $response->successful()) {
            throw UnableToWriteFile::atLocation($path);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $body = stream_get_contents($contents);
        $this->write($path, $body === false ? '' : $body, $config);
    }

    public function read(string $path): string
    {
        $response = Http::timeout(30)->get($this->url($path));
        if (! $response->successful()) {
            throw UnableToReadFile::fromLocation($path);
        }

        return $response->body();
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw UnableToReadFile::fromLocation($path);
        }
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        Http::timeout(15)->delete($this->url($path));
    }

    public function deleteDirectory(string $path): void
    {
        $prefix = trim($path, '/');
        foreach ($this->listContents($prefix, true) as $item) {
            if ($item instanceof FileAttributes) {
                $this->delete($item->path());
            }
        }
    }

    public function createDirectory(string $path, Config $config): void {}

    public function setVisibility(string $path, string $visibility): void {}

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        $response = Http::timeout(15)->head($this->url($path));
        $type = $response->header('content-type');
        if (! $response->successful() || $type === '') {
            throw UnableToRetrieveMetadata::mimeType($path);
        }

        return new FileAttributes($path, null, null, null, $type);
    }

    public function lastModified(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        $response = Http::timeout(15)->head($this->url($path));
        $length = $response->header('content-length');
        if (! $response->successful() || $length === '') {
            throw UnableToRetrieveMetadata::fileSize($path);
        }

        return new FileAttributes($path, (int) $length);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $response = Http::timeout(15)->get($this->url(''));
        $objects = $response->json('objects');
        $prefix = trim($path, '/');
        if (! is_array($objects)) {
            return;
        }
        foreach ($objects as $object) {
            if (! is_array($object) || ! is_string($object['key'] ?? null)) {
                continue;
            }
            $key = $object['key'];
            if ($prefix !== '' && ! str_starts_with($key, $prefix.'/') && $key !== $prefix) {
                continue;
            }
            if (! $deep && $prefix !== '' && str_contains(substr($key, strlen($prefix) + 1), '/')) {
                continue;
            }
            yield new FileAttributes($key, isset($object['size']) ? (int) $object['size'] : null);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (UnableToCopyFile) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->write($destination, $this->read($source), $config);
        } catch (UnableToReadFile|UnableToWriteFile) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }
    }

    private function url(string $path): string
    {
        $path = ltrim($path, '/');

        return 'http://'.$this->host.($path === '' ? '/' : '/'.$path);
    }
}
