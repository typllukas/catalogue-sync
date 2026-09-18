<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/squizlabs/php_codesniffer/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__) . '/.env');

if (filter_var($_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)) {
    umask(0000);
}
