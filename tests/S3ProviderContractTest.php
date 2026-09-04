<?php

declare(strict_types=1);

namespace SohoPHP\SoFinderS3\Tests;

use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use SohoPHP\SoFinder\Testing\StorageAdapterContractVerifier;
use SohoPHP\SoFinder\Value\ResourceType;
use SohoPHP\SoFinderS3\S3StorageAdapterFactory;

final class S3ProviderContractTest extends TestCase
{
    public function testConfiguredProviderSatisfiesThePublicStorageContract(): void
    {
        $provider = trim((string) getenv('SOFINDER_S3_CONTRACT_PROVIDER'));
        if ($provider === '') {
            self::markTestSkipped('Set SOFINDER_S3_CONTRACT_PROVIDER to run an external provider contract.');
        }

        $bucket = $this->requiredEnvironment('SOFINDER_S3_BUCKET');
        $region = $this->requiredEnvironment('SOFINDER_S3_REGION');
        $accessKey = $this->requiredEnvironment('SOFINDER_S3_ACCESS_KEY');
        $secretKey = $this->requiredEnvironment('SOFINDER_S3_SECRET_KEY');
        $endpoint = trim((string) getenv('SOFINDER_S3_ENDPOINT'));
        $pathStyle = filter_var(getenv('SOFINDER_S3_PATH_STYLE') ?: 'false', FILTER_VALIDATE_BOOL);
        $root = sprintf('sofinder-provider-contract/%s/%s', preg_replace('/[^a-z0-9-]/', '-', strtolower($provider)), bin2hex(random_bytes(6)));
        $resource = new ResourceType('Contract', $root, '', ['txt']);
        $options = [
            'bucket' => $bucket,
            'region' => $region,
            'use_path_style_endpoint' => $pathStyle,
            'access_key_id' => $accessKey,
            'secret_access_key' => $secretKey,
            'session_token' => trim((string) getenv('SOFINDER_S3_SESSION_TOKEN')),
            'health_timeout_seconds' => 15,
        ];
        if ($endpoint !== '') {
            $options['endpoint'] = $endpoint;
            $options['allow_insecure_endpoint'] = str_starts_with($endpoint, 'http://');
        }

        $credentials = ['key' => $accessKey, 'secret' => $secretKey];
        if ($options['session_token'] !== '') {
            $credentials['token'] = $options['session_token'];
        }
        $clientOptions = [
            'version' => 'latest',
            'region' => $region,
            'use_path_style_endpoint' => $pathStyle,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'credentials' => $credentials,
        ];
        if ($endpoint !== '') {
            $clientOptions['endpoint'] = $endpoint;
        }
        $client = new S3Client($clientOptions);

        if (filter_var(getenv('SOFINDER_S3_CREATE_BUCKET') ?: 'false', FILTER_VALIDATE_BOOL)) {
            if (!$client->doesBucketExistV2($bucket)) {
                $client->createBucket(['Bucket' => $bucket]);
            }
        }

        $adapter = (new S3StorageAdapterFactory())->create($resource, $options);
        try {
            StorageAdapterContractVerifier::verify($adapter);
        } finally {
            $this->purgeVersions($client, $bucket, $root . '/');
        }
    }

    private function requiredEnvironment(string $name): string
    {
        $value = trim((string) getenv($name));
        if ($value === '') {
            self::fail(sprintf('%s is required when an S3 provider contract is enabled.', $name));
        }

        return $value;
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
