<?php

declare(strict_types=1);

/**
 * Application settings. Merges static defaults with the account-root
 * secrets.php (which must never be committed). All filesystem paths are
 * derived from the account root so the same code runs locally and on Ionos.
 */

$root = dirname(__DIR__, 2); // account root: contains app/, members/, uploads/…

$secretsFile = $root . '/secrets.php';
$secrets = is_file($secretsFile) ? require $secretsFile : [];

return [
    'root'    => $root,
    'env'     => $secrets['env'] ?? 'prod',
    'debug'   => ($secrets['env'] ?? 'prod') === 'dev',

    'paths' => [
        'uploads'      => $root . '/uploads',
        'log_file'     => $root . '/app_logs/membership.log',
        'migrations'   => $root . '/app/migrations',
        'templates'    => $root . '/app/templates',
        'pricing_data' => $root . '/pricing_data',
    ],

    'db'         => $secrets['db'] ?? [],
    'ballejaune' => ($secrets['ballejaune'] ?? []) + [
        'base_url' => 'https://ballejaune.com/api/v1',
    ],
    'sumup'      => $secrets['sumup'] ?? [],
    'smtp'       => $secrets['smtp'] ?? [],
    'app_key'    => $secrets['app_key'] ?? '',

    'club' => [
        'name'     => 'Bad & Squash La Garenne-Colombes',
        'city_zip' => '92250', // La Garenne-Colombes ⇒ tarif « Garennois »
    ],
];
