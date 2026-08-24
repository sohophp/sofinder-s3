<?php

declare(strict_types=1);

namespace SohoPHP\SoFinderS3;

interface S3GatewayInterface
{
    /** @return array{objects:list<array{key:string,size:int,modifiedAt:int,mimeType:?string}>,prefixes:list<string>,nextToken:?string} */
    public function list(string $prefix, ?string $delimiter = null, ?string $token = null, int $limit = 1000): array;

    /** @return array{key:string,size:int,modifiedAt:int,mimeType:?string}|null */
    public function head(string $key): ?array;

    /** @param resource $stream */
    public function put(string $key, mixed $stream, string $mimeType, bool $overwrite): void;

    /** @return resource */
    public function read(string $key): mixed;

    public function copy(string $source, string $destination): void;

    /** @param list<string> $keys */
    public function delete(array $keys): void;

    public function checkBucket(): void;
}
