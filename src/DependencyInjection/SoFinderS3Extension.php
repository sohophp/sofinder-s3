<?php

declare(strict_types=1);

namespace SohoPHP\SoFinderS3\DependencyInjection;

use SohoPHP\SoFinderS3\S3StorageAdapterFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class SoFinderS3Extension extends Extension
{
    /** @param array<array<string,mixed>> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->setDefinition(S3StorageAdapterFactory::class, (new Definition(S3StorageAdapterFactory::class))->addTag('sofinder.storage_factory'));
    }
}
