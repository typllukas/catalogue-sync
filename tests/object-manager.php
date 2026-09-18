<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__ . '/../.env');

$environment = $_SERVER['APP_ENV'] ?? 'dev';
$kernel = new Kernel(
    is_string($environment) ? $environment : 'dev',
    filter_var($_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
);
$kernel->boot();

$doctrine = $kernel->getContainer()->get('doctrine');

return $doctrine->getManager();
