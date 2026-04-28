<?php

declare(strict_types=1);

use CakkTransport\App;

require __DIR__ . '/src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file($file)) {
    return false;
}

(new App())->handle($method, $path);
