<?php

declare(strict_types=1);

namespace SohoPHP\SoFinderS3\Tests;

use Aws\CommandPool;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use SohoPHP\SoFinder\Exception\NotFoundException;
use SohoPHP\SoFinder\Value\ListQuery;
use SohoPHP\SoFinder\Value\ResourceType;
use SohoPHP\SoFinderS3\S3StorageAdapterFactory;

final class S3MinioIntegrationTest extends TestCase
{
    public function testMinioCrudAndRecursiveOperations(): void
    {
        $endpoint = (string) getenv('SOFINDER_S3_ENDPOINT');
        if ($endpoint === '') {
            self::markTestSkipped('Set SOFINDER_S3_ENDPOINT to run the MinIO integration test.');
        }
        $bucket = (string) (getenv('SOFINDER_S3_BUCKET') ?: 'sofinder-test');
        $access = (string) (getenv('SOFINDER_S3_ACCESS_KEY') ?: 'minioadmin');
        $secret = (string) (getenv('SOFINDER_S3_SECRET_KEY') ?: 'minioadmin');
        $client = new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $endpoint, 'use_path_style_endpoint' => true, 'credentials' => ['key' => $access, 'secret' => $secret]]);
        if (!$client->doesBucketExistV2($bucket)) {
            $client->createBucket(['Bucket' => $bucket]);
        }
        $root = 'integration/' . bin2hex(random_bytes(6));
        $resource = new ResourceType('Remote', $root, '', ['txt'], maxRecursiveItems: 2_000);
        $adapter = (new S3StorageAdapterFactory())->create($resource, [
            'bucket' => $bucket,
            'region' => 'us-east-1',
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'allow_insecure_endpoint' => str_starts_with($endpoint, 'http://'),
            'access_key_id' => $access,
            'secret_access_key' => $secret,
        ]);

        $adapter->createDirectory('资料');
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'remote-content');
        rewind($stream);
        $adapter->writeStream('资料/hello +# 文档.txt', $stream);
        fclose($stream);
        self::assertSame(['资料'], array_column($adapter->list(new ListQuery())->entries, 'name'));
        self::assertSame(14, $adapter->usage());
        $adapter->copy('资料', 'copy');
        $adapter->move('copy', 'moved');
        $remote = $adapter->readStream('moved/hello +# 文档.txt');
        self::assertSame('remote-content', stream_get_contents($remote));
        fclose($remote);

        $commands = (static function () use ($client, $bucket, $root): \Generator {
            for ($index = 0; $index < 1_001; ++$index) {
                yield $client->getCommand('PutObject', [
                    'Bucket' => $bucket,
                    'Key' => sprintf('%s/bulk/item-%04d.txt', $root, $index),
                    'Body' => 'x',
                    'ContentType' => 'text/plain',
                ]);
            }
        })();
        (new CommandPool($client, $commands, ['concurrency' => 32]))->promise()->wait();
        $first = $adapter->list(new ListQuery('bulk', limit: 600));
        self::assertCount(600, $first->entries);
        self::assertNotNull($first->nextCursor);
        $second = $adapter->list(new ListQuery('bulk', offset: 600, limit: 600, cursor: $first->nextCursor));
        self::assertCount(401, $second->entries);
        self::assertNull($second->nextCursor);

        $adapter->delete('资料');
        $adapter->delete('moved');
        $adapter->delete('bulk');
        self::assertSame(0, $adapter->usage());
        $this->expectException(NotFoundException::class);
        $adapter->entry('moved/hello +# 文档.txt');
    }
}
