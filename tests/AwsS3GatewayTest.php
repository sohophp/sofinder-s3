<?php

declare(strict_types=1);

namespace SohoPHP\SoFinderS3\Tests;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SohoPHP\SoFinder\Exception\AccessDeniedException;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinderS3\AwsS3Gateway;

final class AwsS3GatewayTest extends TestCase
{
    #[DataProvider('conditionalWriteProvider')]
    public function testConditionalPutHeaderCanBeDisabled(bool $conditionalWrites, bool $expectsHeader): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $command, RequestInterface $request) use ($expectsHeader): Result {
            self::assertSame('PutObject', $command->getName());
            self::assertSame($expectsHeader, $request->hasHeader('If-None-Match'));

            return new Result();
        });
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $handler,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
        ]);
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'test');
        rewind($stream);

        (new AwsS3Gateway($client, 'bucket', $conditionalWrites))->put('file.txt', $stream, 'text/plain', false);
        fclose($stream);
    }

    /** @return iterable<string, array{bool,bool}> */
    public static function conditionalWriteProvider(): iterable
    {
        yield 'AWS default' => [true, true];
        yield 'B2-compatible fallback' => [false, false];
    }

    public function testAccessDeniedIsMappedToAUsefulStoragePermissionError(): void
    {
        $handler = new MockHandler();
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $handler,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
        ]);
        $command = $client->getCommand('ListObjectsV2', ['Bucket' => 'bucket']);
        $handler->append(new AwsException('Forbidden', $command, [
            'code' => 'AccessDenied',
            'response' => new Response(403),
        ]));

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('The remote object storage credentials do not allow this operation.');

        (new AwsS3Gateway($client, 'bucket'))->list('component-images/');
    }

    public function testMissingBucketIsNotReportedAsAMissingEntry(): void
    {
        $handler = new MockHandler();
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $handler,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
        ]);
        $command = $client->getCommand('ListObjectsV2', ['Bucket' => 'missing']);
        $handler->append(new AwsException('Missing bucket', $command, [
            'code' => 'NoSuchBucket',
            'response' => new Response(404),
        ]));

        try {
            (new AwsS3Gateway($client, 'missing'))->list('component-images/');
            self::fail('Expected the missing bucket error to be translated.');
        } catch (SoFinderException $exception) {
            self::assertSame('remote_bucket_not_found', $exception->errorCode);
            self::assertSame(502, $exception->httpStatus);
            self::assertSame('The configured remote object storage bucket was not found or is unavailable.', $exception->getMessage());
        }
    }
}
