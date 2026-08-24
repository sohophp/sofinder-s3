<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$localEnvironment = dirname(__DIR__) . '/.env.local';
if (is_file($localEnvironment)) {
    (new Symfony\Component\Dotenv\Dotenv())->usePutenv()->load($localEnvironment);
}
