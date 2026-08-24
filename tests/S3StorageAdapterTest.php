<?php

declare(strict_types=1);

namespace SohoPHP\SoFinderS3\Tests;

use PHPUnit\Framework\TestCase;
use SohoPHP\SoFinder\Exception\NotFoundException;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Value\ListQuery;
use SohoPHP\SoFinderS3\S3GatewayInterface;
use SohoPHP\SoFinderS3\S3StorageAdapter;

final class S3StorageAdapterTest extends TestCase
{
    public function testCrudVirtualFoldersCursorPaginationAndPublicUrls(): void
    {
        $gateway = new MemoryS3Gateway();
        $adapter = new S3StorageAdapter($gateway, 'tenant/media', 'https://cdn.example.test/media', 100);
        $adapter->createDirectory('docs');
        foreach (['a.txt' => 'alpha', 'b.txt' => 'bravo', 'c.txt' => 'charlie'] as $name => $contents) {
            $stream = fopen('php://temp', 'w+b');
            self::assertIsResource($stream);
            fwrite($stream, $contents);
            rewind($stream);
            $adapter->writeStream('docs/' . $name, $stream);
            fclose($stream);
        }

        $first = $adapter->list(new ListQuery('docs', limit: 2));
        self::assertNull($first->total);
        self::assertNotNull($first->nextCursor);
        self::assertCount(2, $first->entries);
        $second = $adapter->list(new ListQuery('docs', offset: 2, limit: 2, cursor: $first->nextCursor));
        self::assertCount(1, $second->entries);
        self::assertSame('https://cdn.example.test/media/docs/c.txt', $second->entries[0]->url);

        $stream = $adapter->readStream('docs/a.txt');
        self::assertSame('alpha', stream_get_contents($stream));
        fclose($stream);
        self::assertSame(17, $adapter->usage());

        $adapter->delete('docs');
        self::assertSame([], $gateway->keys());
        $this->expectException(NotFoundException::class);
        $adapter->entry('docs/a.txt');
    }

    public function testDirectoryMoveCopiesEverythingBeforeDeletingTheSource(): void
    {
        $gateway = new MemoryS3Gateway();
        $adapter = new S3StorageAdapter($gateway, '/', '', 100);
        $adapter->createDirectory('source');
        foreach (['one.txt', 'two.txt'] as $name) {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $name);
            rewind($stream);
            $adapter->writeStream('source/' . $name, $stream);
            fclose($stream);
        }

        $gateway->failCopyAt = 2;
        try {
            $adapter->move('source', 'destination');
            self::fail('The injected copy failure should abort the move.');
        } catch (SoFinderException $exception) {
            self::assertSame('remote_storage_error', $exception->errorCode);
        }
        self::assertContains('source/one.txt', $gateway->keys());
        self::assertContains('source/two.txt', $gateway->keys());
        self::assertSame([], array_values(array_filter($gateway->keys(), static fn (string $key): bool => str_starts_with($key, 'destination/'))));
    }

    public function testAuditDoesNotExposeGatewayErrors(): void
    {
        $gateway = new MemoryS3Gateway();
        $gateway->bucketAvailable = false;
        $findings = (new S3StorageAdapter($gateway, '/', secureEndpoint: false))->auditStorage();

        self::assertSame(['warning', 'critical'], array_column($findings, 'severity'));
        self::assertStringNotContainsString('secret', implode(' ', array_column($findings, 'message')));
    }

    public function testRecursiveOperationsRespectTheConfiguredObjectLimit(): void
    {
        $gateway = new MemoryS3Gateway();
        $adapter = new S3StorageAdapter($gateway, '/', maxRecursiveItems: 2);
        $adapter->createDirectory('source');
        foreach (['one.txt', 'two.txt'] as $name) {
            $stream = fopen('php://temp', 'w+b');
            self::assertIsResource($stream);
            fwrite($stream, $name);
            rewind($stream);
            $adapter->writeStream('source/' . $name, $stream);
            fclose($stream);
        }

        try {
            $adapter->copy('source', 'destination');
            self::fail('The recursive object limit should reject this copy.');
        } catch (SoFinderException $exception) {
            self::assertSame('recursive_limit_exceeded', $exception->errorCode);
        }
        self::assertSame([], array_values(array_filter($gateway->keys(), static fn (string $key): bool => str_starts_with($key, 'destination/'))));
    }
}

final class MemoryS3Gateway implements S3GatewayInterface
{
    /** @var array<string,array{body:string,mimeType:string,modifiedAt:int}> */
    private array $objects = [];
    public ?int $failCopyAt = null;
    public bool $bucketAvailable = true;
    private int $copyCount = 0;

    public function list(string $prefix, ?string $delimiter = null, ?string $token = null, int $limit = 1000): array
    {
        $keys = array_values(array_filter(array_keys($this->objects), static fn (string $key): bool => str_starts_with($key, $prefix)));
        sort($keys, SORT_STRING);
        $items = [];
        $prefixes = [];
        foreach ($keys as $key) {
            $remainder = substr($key, strlen($prefix));
            if ($delimiter !== null && str_contains($remainder, $delimiter)) {
                $segment = strstr($remainder, $delimiter, true);
                $prefixes[$prefix . $segment . $delimiter] = true;
            } else {
                $items[] = ['kind' => 'object', 'value' => $key];
            }
        }
        foreach (array_keys($prefixes) as $value) {
            $items[] = ['kind' => 'prefix', 'value' => $value];
        }
        usort($items, static fn (array $left, array $right): int => strcmp($left['value'], $right['value']));
        $offset = $token === null ? 0 : (int) $token;
        $slice = array_slice($items, $offset, $limit);
        $objects = [];
        $common = [];
        foreach ($slice as $item) {
            if ($item['kind'] === 'prefix') {
                $common[] = $item['value'];
            } else {
                $object = $this->objects[$item['value']];
                $objects[] = ['key' => $item['value'], 'size' => strlen($object['body']), 'modifiedAt' => $object['modifiedAt'], 'mimeType' => $object['mimeType']];
            }
        }
        $next = $offset + count($slice) < count($items) ? (string) ($offset + count($slice)) : null;

        return ['objects' => $objects, 'prefixes' => $common, 'nextToken' => $next];
    }

    public function head(string $key): ?array
    {
        $object = $this->objects[$key] ?? null;
        return $object === null ? null : ['key' => $key, 'size' => strlen($object['body']), 'modifiedAt' => $object['modifiedAt'], 'mimeType' => $object['mimeType']];
    }

    public function put(string $key, mixed $stream, string $mimeType, bool $overwrite): void
    {
        if (!$overwrite && isset($this->objects[$key])) {
            throw new \SohoPHP\SoFinder\Exception\ConflictException();
        }
        $this->objects[$key] = ['body' => (string) stream_get_contents($stream), 'mimeType' => $mimeType, 'modifiedAt' => time()];
    }

    public function read(string $key): mixed
    {
        if (!isset($this->objects[$key])) {
            throw new \SohoPHP\SoFinder\Exception\NotFoundException();
        }
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->objects[$key]['body']);
        rewind($stream);
        return $stream;
    }

    public function copy(string $source, string $destination): void
    {
        ++$this->copyCount;
        if ($this->failCopyAt === $this->copyCount) {
            throw new SoFinderException('Injected failure.', 'remote_storage_error', 502);
        }
        if (!isset($this->objects[$source])) {
            throw new \SohoPHP\SoFinder\Exception\NotFoundException();
        }
        $this->objects[$destination] = $this->objects[$source];
    }

    public function delete(array $keys): void
    {
        foreach ($keys as $key) {
            unset($this->objects[$key]);
        }
    }

    public function checkBucket(): void
    {
        if (!$this->bucketAvailable) {
            throw new \RuntimeException('secret gateway diagnostic');
        }
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->objects);
        sort($keys, SORT_STRING);
        return $keys;
    }
}
