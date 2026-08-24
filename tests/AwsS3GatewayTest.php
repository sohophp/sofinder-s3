<?php

declare(strict_types=1);

namespace SohoPHP\SoFinderS3\Tests;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
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
}
