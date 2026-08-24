<?php

declare(strict_types=1);

namespace SohoPHP\SoFinderS3;

use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use SohoPHP\SoFinder\Contract\StorageAdapterFactoryInterface;
use SohoPHP\SoFinder\Contract\StorageAdapterInterface;
use SohoPHP\SoFinder\Value\ResourceType;

final class S3StorageAdapterFactory implements StorageAdapterFactoryInterface
{
    public function alias(): string
    {
        return 's3';
    }

    public function create(ResourceType $resource, array $options = []): StorageAdapterInterface
    {
        $bucket = trim((string) ($options['bucket'] ?? ''));
        $region = trim((string) ($options['region'] ?? ''));
        if ($bucket === '' || $region === '') {
            throw new \InvalidArgumentException('S3 storage requires non-empty bucket and region options.');
        }
        $endpoint = trim((string) ($options['endpoint'] ?? ''));
        $allowInsecure = (bool) ($options['allow_insecure_endpoint'] ?? false);
        $secureEndpoint = true;
        if ($endpoint !== '') {
            $parts = parse_url($endpoint);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
                throw new \InvalidArgumentException('The S3 endpoint must be an absolute URL without credentials, query or fragment.');
            }
            $scheme = strtolower((string) $parts['scheme']);
            if ($scheme !== 'https' && !($scheme === 'http' && $allowInsecure)) {
                throw new \InvalidArgumentException('The S3 endpoint must use HTTPS unless allow_insecure_endpoint is explicitly enabled.');
            }
            $secureEndpoint = $scheme === 'https';
        }
        $clientOptions = [
            'version' => 'latest',
            'region' => $region,
            'use_path_style_endpoint' => (bool) ($options['use_path_style_endpoint'] ?? false),
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ];
        if ($endpoint !== '') {
            $clientOptions['endpoint'] = rtrim($endpoint, '/');
        }
        $accessKey = (string) ($options['access_key_id'] ?? '');
        $secretKey = (string) ($options['secret_access_key'] ?? '');
        if (($accessKey === '') !== ($secretKey === '')) {
            throw new \InvalidArgumentException('S3 access_key_id and secret_access_key must be configured together.');
        }
        if ($accessKey !== '') {
            $clientOptions['credentials'] = new Credentials($accessKey, $secretKey, ($options['session_token'] ?? '') !== '' ? (string) $options['session_token'] : null);
        }
        $gateway = new AwsS3Gateway(new S3Client($clientOptions), $bucket);

        return new S3StorageAdapter($gateway, $resource->root, $resource->publicUrl, $resource->maxRecursiveItems, $secureEndpoint);
    }
}
