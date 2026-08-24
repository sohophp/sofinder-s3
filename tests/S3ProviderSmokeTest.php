<?php

declare(strict_types=1);

namespace SohoPHP\SoFinderS3\Tests;

use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use SohoPHP\SoFinder\Value\ListQuery;
use SohoPHP\SoFinder\Value\ResourceType;
use SohoPHP\SoFinderS3\S3StorageAdapter;
use SohoPHP\SoFinderS3\S3StorageAdapterFactory;

final class S3ProviderSmokeTest extends TestCase
{
    public function testProviderCrudContractInsideRandomPrefix(): void
    {
        $endpoint = $this->requiredEnvironment('SOFINDER_PROVIDER_ENDPOINT');
        if ($endpoint === null) {
            self::markTestSkipped('Set SOFINDER_PROVIDER_ENDPOINT and the other SOFINDER_PROVIDER_* variables to run the external provider smoke test.');
        }

        $bucket = $this->requiredEnvironment('SOFINDER_PROVIDER_BUCKET');
        $region = $this->requiredEnvironment('SOFINDER_PROVIDER_REGION');
        $accessKey = $this->requiredEnvironment('SOFINDER_PROVIDER_ACCESS_KEY');
        $secretKey = $this->requiredEnvironment('SOFINDER_PROVIDER_SECRET_KEY');
        self::assertNotNull($bucket);
        self::assertNotNull($region);
        self::assertNotNull($accessKey);
        self::assertNotNull($secretKey);

        $configuredPrefix = trim((string) getenv('SOFINDER_PROVIDER_PREFIX'), '/');
        $root = ($configuredPrefix === '' ? '' : $configuredPrefix.'/')
            .'provider-smoke/'.bin2hex(random_bytes(8));
        $resource = new ResourceType('ProviderSmoke', $root, '', ['txt'], maxRecursiveItems: 100);
        $pathStyle = $this->environmentFlag('SOFINDER_PROVIDER_USE_PATH_STYLE_ENDPOINT');
        $sessionToken = (string) (getenv('SOFINDER_PROVIDER_SESSION_TOKEN') ?: '');
        $adapter = (new S3StorageAdapterFactory())->create($resource, [
            'bucket' => $bucket,
            'region' => $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => $pathStyle,
            'access_key_id' => $accessKey,
            'secret_access_key' => $secretKey,
            'session_token' => $sessionToken,
        ]);
        self::assertInstanceOf(S3StorageAdapter::class, $adapter);
        $credentials = ['key' => $accessKey, 'secret' => $secretKey];
        if ($sessionToken !== '') {
            $credentials['token'] = $sessionToken;
        }
        $client = new S3Client([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => $pathStyle,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'credentials' => $credentials,
        ]);

        try {
            $adapter->createDirectory('资料');
            $stream = fopen('php://temp', 'w+b');
            self::assertIsResource($stream);
            fwrite($stream, 'provider-smoke');
            rewind($stream);
            $adapter->writeStream('资料/hello +# 文档.txt', $stream);
            fclose($stream);

            self::assertSame(['资料'], array_column($adapter->list(new ListQuery())->entries, 'name'));
            self::assertSame(14, $adapter->usage());
            self::assertSame('provider-smoke', $this->read($adapter, '资料/hello +# 文档.txt'));

            $adapter->copy('资料/hello +# 文档.txt', 'copy.txt');
            $adapter->move('copy.txt', 'moved.txt');
            self::assertSame('provider-smoke', $this->read($adapter, 'moved.txt'));

            $findings = $adapter->auditStorage();
            self::assertSame([], array_values(array_filter(
                $findings,
                static fn (array $finding): bool => ($finding['severity'] ?? '') === 'critical',
            )));

            $adapter->delete('资料');
            $adapter->delete('moved.txt');
            self::assertSame(0, $adapter->usage());
        } finally {
            foreach (['资料', 'copy.txt', 'moved.txt'] as $path) {
                try {
                    $adapter->delete($path);
                } catch (\Throwable) {
                    // Cleanup is best-effort and remains confined to the random root.
                }
            }
            try {
                $this->purgeVersions($client, $bucket, $root.'/');
            } catch (\Throwable $error) {
                fwrite(STDERR, sprintf("B2 provider-smoke version cleanup failed: %s\n", $error->getMessage()));
            }
        }
    }

    private function read(S3StorageAdapter $adapter, string $path): string
    {
        $stream = $adapter->readStream($path);
        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    private function requiredEnvironment(string $name): ?string
    {
        $value = trim((string) getenv($name));

        return $value === '' ? null : $value;
    }

    private function environmentFlag(string $name): bool
    {
        return in_array(strtolower(trim((string) getenv($name))), ['1', 'true', 'yes', 'on'], true);
    }

    private function purgeVersions(S3Client $client, string $bucket, string $prefix): void
    {
        $objects = [];
        foreach ($client->getPaginator('ListObjectVersions', ['Bucket' => $bucket, 'Prefix' => $prefix]) as $page) {
            foreach (['Versions', 'DeleteMarkers'] as $collection) {
                foreach ($page[$collection] ?? [] as $version) {
                    $key = (string) ($version['Key'] ?? '');
                    $versionId = (string) ($version['VersionId'] ?? '');
                    if ($key !== '' && $versionId !== '' && str_starts_with($key, $prefix)) {
                        $objects[] = ['Key' => $key, 'VersionId' => $versionId];
                    }
                }
            }
        }
        foreach (array_chunk($objects, 1_000) as $chunk) {
            $client->deleteObjects([
                'Bucket' => $bucket,
                'Delete' => ['Objects' => $chunk, 'Quiet' => true],
            ]);
        }
    }
}
