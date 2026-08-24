<?php

declare(strict_types=1);

namespace SohoPHP\SoFinderS3;

use SohoPHP\SoFinder\Contract\StorageAdapterInterface;
use SohoPHP\SoFinder\Contract\StorageAuditProviderInterface;
use SohoPHP\SoFinder\Contract\StorageUsageProviderInterface;
use SohoPHP\SoFinder\Exception\ConflictException;
use SohoPHP\SoFinder\Exception\NotFoundException;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Security\PathGuard;
use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\ListQuery;
use SohoPHP\SoFinder\Value\ListingPage;
use SohoPHP\SoFinder\Value\StorageCapabilities;

final readonly class S3StorageAdapter implements StorageAdapterInterface, StorageUsageProviderInterface, StorageAuditProviderInterface
{
    private string $prefix;

    public function __construct(
        private S3GatewayInterface $gateway,
        string $root,
        private string $baseUrl = '',
        private int $maxRecursiveItems = 10_000,
        private bool $secureEndpoint = true,
        private PathGuard $paths = new PathGuard(),
    ) {
        $this->prefix = $root === '/' ? '' : $this->paths->normalize($root);
    }

    public function list(ListQuery $query): ListingPage
    {
        if (trim($query->search) !== '') {
            throw new SoFinderException('S3 storage does not support server-side substring search.', 'storage_search_unsupported', 422);
        }
        if ($query->sort !== 'name' || $query->direction !== 'asc') {
            throw new SoFinderException('S3 storage supports its native ascending key order only.', 'storage_sort_unsupported', 422);
        }
        $path = $this->paths->normalize($query->path);
        $directoryPrefix = $this->directoryKey($path);
        $entries = [];
        $token = $query->cursor;
        do {
            $page = $this->gateway->list($directoryPrefix, '/', $token, max(1, $query->limit - count($entries)));
            foreach ($page['prefixes'] as $prefix) {
                $logical = $this->logicalPath(rtrim($prefix, '/'));
                if ($logical !== '' && $logical !== $path) {
                    $entries[$logical] = new Entry($logical, basename($logical), true, 0, 0);
                }
            }
            foreach ($page['objects'] as $object) {
                if ($object['key'] === $directoryPrefix) {
                    continue;
                }
                if (str_ends_with($object['key'], '/')) {
                    $logical = $this->logicalPath(rtrim($object['key'], '/'));
                    if ($logical !== '') {
                        $entries[$logical] = new Entry($logical, basename($logical), true, 0, $object['modifiedAt']);
                    }
                    continue;
                }
                $logical = $this->logicalPath($object['key']);
                $entries[$logical] = $this->fileEntry($logical, $object);
            }
            $token = $page['nextToken'];
        } while (count($entries) < $query->limit && $token !== null);
        $entries = array_values($entries);
        if ($query->onlyPaths !== null) {
            $allowed = array_fill_keys($query->onlyPaths, true);
            $entries = array_values(array_filter($entries, static fn (Entry $entry): bool => isset($allowed[$entry->path])));
        }
        if ($query->filter !== null) {
            $entries = array_values(array_filter($entries, $query->filter));
        }

        return new ListingPage($entries, null, $query->offset, $query->limit, $token);
    }

    public function capabilities(): StorageCapabilities
    {
        return new StorageCapabilities(search: false, sort: false, cursorPagination: true, atomicMove: false, nativeCopy: true, recoverableDelete: false, publicUrl: $this->baseUrl !== '');
    }

    public function entry(string $path): Entry
    {
        $path = $this->paths->normalize($path);
        if ($path === '') {
            return new Entry('', '', true, 0, 0);
        }
        $key = $this->key($path);
        $object = $this->gateway->head($key);
        if ($object !== null) {
            return $this->fileEntry($path, $object);
        }
        $marker = $this->gateway->head($key . '/');
        if ($marker !== null) {
            return new Entry($path, basename($path), true, 0, $marker['modifiedAt']);
        }
        $children = $this->gateway->list($key . '/', null, null, 1);
        if ($children['objects'] !== [] || $children['prefixes'] !== []) {
            return new Entry($path, basename($path), true, 0, 0);
        }
        throw new NotFoundException();
    }

    public function createDirectory(string $path): Entry
    {
        $path = $this->paths->normalize($path);
        if ($path === '') {
            throw new SoFinderException('A folder name is required.', 'invalid_path', 400);
        }
        if ($this->exists($path)) {
            throw new ConflictException();
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new SoFinderException('Unable to prepare the folder marker.', 'remote_storage_error', 502);
        }
        try {
            $this->gateway->put($this->key($path) . '/', $stream, 'application/x-directory', false);
        } finally {
            fclose($stream);
        }

        return $this->entry($path);
    }

    public function writeStream(string $path, mixed $stream, bool $overwrite = false): Entry
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('writeStream expects a stream resource.');
        }
        $path = $this->paths->normalize($path);
        if (!$overwrite && $this->exists($path)) {
            throw new ConflictException();
        }
        $this->gateway->put($this->key($path), $stream, $this->detectMime($stream), $overwrite);

        return $this->entry($path);
    }

    public function readStream(string $path): mixed
    {
        $path = $this->paths->normalize($path);
        $entry = $this->entry($path);
        if ($entry->directory) {
            throw new SoFinderException('Folders have no readable content.', 'invalid_path', 400);
        }

        return $this->gateway->read($this->key($path));
    }

    public function move(string $source, string $destination, bool $overwrite = false): Entry
    {
        $entry = $this->copyInternal($source, $destination, $overwrite);
        $this->delete($source);

        return $entry;
    }

    public function copy(string $source, string $destination, bool $overwrite = false): Entry
    {
        return $this->copyInternal($source, $destination, $overwrite);
    }

    public function delete(string $path): void
    {
        $path = $this->paths->normalize($path);
        if ($path === '') {
            throw new SoFinderException('The storage root cannot be deleted.', 'invalid_path', 400);
        }
        $entry = $this->entry($path);
        $keys = $entry->directory ? $this->treeKeys($path) : [$this->key($path)];
        $this->gateway->delete($keys);
    }

    public function publicUrl(string $path): ?string
    {
        if ($this->baseUrl === '') {
            return null;
        }
        $path = $this->paths->normalize($path);
        $encoded = implode('/', array_map('rawurlencode', $path === '' ? [] : explode('/', $path)));

        return rtrim($this->baseUrl, '/') . ($encoded === '' ? '/' : '/' . $encoded);
    }

    public function size(string $path): int
    {
        $entry = $this->entry($path);
        if (!$entry->directory) {
            return $entry->size;
        }
        $size = 0;
        foreach ($this->treeObjects($path) as $object) {
            if (!str_ends_with($object['key'], '/')) {
                $size += $object['size'];
            }
        }

        return $size;
    }

    public function usage(): int
    {
        $size = 0;
        foreach ($this->allObjects($this->directoryKey('')) as $object) {
            if (!str_ends_with($object['key'], '/')) {
                $size += $object['size'];
            }
        }

        return $size;
    }

    public function auditStorage(): array
    {
        $findings = [];
        if (!$this->secureEndpoint) {
            $findings[] = ['severity' => 'warning', 'message' => 'The S3 endpoint uses unencrypted HTTP and must be limited to a trusted development network.'];
        }
        try {
            $this->gateway->checkBucket();
        } catch (\Throwable) {
            $findings[] = ['severity' => 'critical', 'message' => 'The configured S3 bucket is unavailable with the current credentials.'];
        }

        return $findings;
    }

    private function copyInternal(string $source, string $destination, bool $overwrite): Entry
    {
        $source = $this->paths->normalize($source);
        $destination = $this->paths->normalize($destination);
        if ($source === '' || $destination === '' || $source === $destination || str_starts_with($destination, $source . '/')) {
            throw new SoFinderException('The copy or move destination is invalid.', 'invalid_path', 400);
        }
        $entry = $this->entry($source);
        if ($entry->directory && $overwrite) {
            throw new SoFinderException('Overwriting an S3 directory is not supported.', 'storage_overwrite_unsupported', 422);
        }
        if (!$overwrite && $this->exists($destination)) {
            throw new ConflictException();
        }
        $pairs = $entry->directory
            ? array_map(fn (array $object): array => [$object['key'], $this->key($destination) . substr($object['key'], strlen($this->key($source)))], $this->treeObjects($source))
            : [[$this->key($source), $this->key($destination)]];
        $created = [];
        try {
            foreach ($pairs as [$sourceKey, $destinationKey]) {
                $this->gateway->copy($sourceKey, $destinationKey);
                $created[] = $destinationKey;
            }
        } catch (\Throwable $exception) {
            try {
                $this->gateway->delete($created);
            } catch (\Throwable) {
            }
            throw $exception;
        }

        return $this->entry($destination);
    }

    /** @return list<string> */
    private function treeKeys(string $path): array
    {
        return array_column($this->treeObjects($path), 'key');
    }

    /** @return list<array{key:string,size:int,modifiedAt:int,mimeType:?string}> */
    private function treeObjects(string $path): array
    {
        return $this->allObjects($this->key($path) . '/');
    }

    /** @return list<array{key:string,size:int,modifiedAt:int,mimeType:?string}> */
    private function allObjects(string $prefix): array
    {
        $objects = [];
        $token = null;
        do {
            $page = $this->gateway->list($prefix, null, $token, 1000);
            array_push($objects, ...$page['objects']);
            if (count($objects) > $this->maxRecursiveItems) {
                throw new SoFinderException('The remote operation contains too many objects.', 'recursive_limit_exceeded', 413);
            }
            $token = $page['nextToken'];
        } while ($token !== null);

        return $objects;
    }

    private function exists(string $path): bool
    {
        try {
            $this->entry($path);
            return true;
        } catch (NotFoundException) {
            return false;
        }
    }

    /** @param array{key:string,size:int,modifiedAt:int,mimeType:?string} $object */
    private function fileEntry(string $path, array $object): Entry
    {
        $mimeType = $object['mimeType'];
        if ($mimeType === null) {
            $head = $this->gateway->head($object['key']);
            $mimeType = $head['mimeType'] ?? 'application/octet-stream';
        }

        return new Entry($path, basename($path), false, $object['size'], $object['modifiedAt'], $mimeType, $this->publicUrl($path));
    }

    private function key(string $path): string
    {
        $path = $this->paths->normalize($path);
        return $this->prefix === '' ? $path : $this->prefix . ($path === '' ? '' : '/' . $path);
    }

    private function directoryKey(string $path): string
    {
        $key = $this->key($path);
        return $key === '' ? '' : rtrim($key, '/') . '/';
    }

    private function logicalPath(string $key): string
    {
        if ($this->prefix === '') {
            return $this->paths->normalize($key);
        }
        $prefix = $this->prefix . '/';
        if (!str_starts_with($key, $prefix)) {
            throw new SoFinderException('The S3 service returned an object outside the configured root.', 'remote_storage_error', 502);
        }
        return $this->paths->normalize(substr($key, strlen($prefix)));
    }

    /** @param resource $stream */
    private function detectMime(mixed $stream): string
    {
        $position = ftell($stream);
        $sample = fread($stream, 8192);
        if ($position !== false) {
            fseek($stream, $position);
        }
        if (!is_string($sample)) {
            return 'application/octet-stream';
        }
        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($sample) ?: 'application/octet-stream';
    }
}
