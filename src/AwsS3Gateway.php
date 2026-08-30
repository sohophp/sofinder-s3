<?php

declare(strict_types=1);

namespace SohoPHP\SoFinderS3;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use SohoPHP\SoFinder\Exception\AccessDeniedException;
use SohoPHP\SoFinder\Exception\ConflictException;
use SohoPHP\SoFinder\Exception\NotFoundException;
use SohoPHP\SoFinder\Exception\SoFinderException;

final class AwsS3Gateway implements S3GatewayInterface
{
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly bool $conditionalWrites = true,
    ) {
    }

    public function list(string $prefix, ?string $delimiter = null, ?string $token = null, int $limit = 1000): array
    {
        try {
            $arguments = ['Bucket' => $this->bucket, 'Prefix' => $prefix, 'MaxKeys' => max(1, min(1000, $limit))];
            if ($delimiter !== null) {
                $arguments['Delimiter'] = $delimiter;
            }
            if ($token !== null) {
                $arguments['ContinuationToken'] = $token;
            }
            $result = $this->client->listObjectsV2($arguments);
            $objects = [];
            foreach ((array) ($result['Contents'] ?? []) as $object) {
                $objects[] = [
                    'key' => (string) ($object['Key'] ?? ''),
                    'size' => (int) ($object['Size'] ?? 0),
                    'modifiedAt' => $this->timestamp($object['LastModified'] ?? null),
                    'mimeType' => null,
                ];
            }
            $prefixes = array_values(array_filter(array_map(
                static fn (mixed $value): string => (string) ((array) $value)['Prefix'],
                (array) ($result['CommonPrefixes'] ?? []),
            )));

            return ['objects' => $objects, 'prefixes' => $prefixes, 'nextToken' => isset($result['NextContinuationToken']) ? (string) $result['NextContinuationToken'] : null];
        } catch (AwsException $exception) {
            throw $this->translate($exception);
        }
    }

    public function head(string $key): ?array
    {
        try {
            $result = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key]);

            return ['key' => $key, 'size' => (int) ($result['ContentLength'] ?? 0), 'modifiedAt' => $this->timestamp($result['LastModified'] ?? null), 'mimeType' => isset($result['ContentType']) ? (string) $result['ContentType'] : null];
        } catch (AwsException $exception) {
            if ($this->status($exception) === 404 || in_array($exception->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true)) {
                return null;
            }
            throw $this->translate($exception);
        }
    }

    public function put(string $key, mixed $stream, string $mimeType, bool $overwrite): void
    {
        try {
            $arguments = ['Bucket' => $this->bucket, 'Key' => $key, 'Body' => $stream, 'ContentType' => $mimeType];
            if (!$overwrite && $this->conditionalWrites) {
                $arguments['IfNoneMatch'] = '*';
            }
            $this->client->putObject($arguments);
        } catch (AwsException $exception) {
            throw $this->translate($exception);
        }
    }

    public function read(string $key): mixed
    {
        try {
            $result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
            $body = $result['Body'] ?? null;
            $stream = is_object($body) && method_exists($body, 'detach') ? $body->detach() : null;
            if (!is_resource($stream)) {
                $stream = fopen('php://temp', 'w+b');
                if ($stream === false) {
                    throw new SoFinderException('Unable to prepare the remote object stream.', 'remote_storage_error', 502);
                }
                fwrite($stream, (string) $body);
                rewind($stream);
            }

            return $stream;
        } catch (AwsException $exception) {
            throw $this->translate($exception);
        }
    }

    public function copy(string $source, string $destination): void
    {
        try {
            $copySource = $this->bucket . '/' . implode('/', array_map('rawurlencode', explode('/', $source)));
            $this->client->copyObject(['Bucket' => $this->bucket, 'Key' => $destination, 'CopySource' => $copySource]);
        } catch (AwsException $exception) {
            throw $this->translate($exception);
        }
    }

    public function delete(array $keys): void
    {
        foreach (array_chunk(array_values(array_unique($keys)), 1000) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            try {
                $result = $this->client->deleteObjects(['Bucket' => $this->bucket, 'Delete' => ['Quiet' => true, 'Objects' => array_map(static fn (string $key): array => ['Key' => $key], $chunk)]]);
                if ((array) ($result['Errors'] ?? []) !== []) {
                    throw new SoFinderException('One or more remote objects could not be deleted.', 'remote_delete_failed', 502);
                }
            } catch (AwsException $exception) {
                throw $this->translate($exception);
            }
        }
    }

    public function checkBucket(): void
    {
        try {
            $this->client->headBucket(['Bucket' => $this->bucket]);
        } catch (AwsException $exception) {
            throw $this->translate($exception);
        }
    }

    private function timestamp(mixed $value): int
    {
        return $value instanceof \DateTimeInterface ? $value->getTimestamp() : (is_string($value) ? (strtotime($value) ?: 0) : 0);
    }

    private function status(AwsException $exception): int
    {
        return (int) ($exception->getStatusCode() ?? 0);
    }

    private function translate(AwsException $exception): SoFinderException
    {
        $status = $this->status($exception);
        $code = (string) $exception->getAwsErrorCode();
        if ($code === 'NoSuchBucket') {
            return new SoFinderException('The configured remote object storage bucket was not found or is unavailable.', 'remote_bucket_not_found', 502, $exception);
        }
        if ($status === 404 || in_array($code, ['NoSuchKey', 'NotFound'], true)) {
            return new NotFoundException();
        }
        if (in_array($status, [401, 403], true) || in_array($code, ['AccessDenied', 'InvalidAccessKeyId', 'SignatureDoesNotMatch'], true)) {
            return new AccessDeniedException('The remote object storage credentials do not allow this operation.');
        }
        if (in_array($status, [409, 412], true) || in_array($code, ['BucketAlreadyExists', 'ConditionalRequestConflict', 'PreconditionFailed'], true)) {
            return new ConflictException();
        }

        return new SoFinderException('The remote object storage request failed.', 'remote_storage_error', 502, $exception);
    }
}
