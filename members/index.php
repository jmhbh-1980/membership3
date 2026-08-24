<?php

declare(strict_types=1);

/**
 * Front controller — the only PHP entry point inside the webroot.
 * Everything else (src/, templates/, config/, vendor/, secrets, uploads,
 * logs) lives one level above DOCUMENT_ROOT and is never web-accessible.
 */

// Serve static files directly when running under the PHP built-in server.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/vendor/autoload.php';

$app = require dirname(__DIR__) . '/app/src/bootstrap.php';
$app->run();
