<?php

declare(strict_types=1);

/**
 * CLI smoke test against the real Balle Jaune API (read-only).
 * Requires the BJ API key in secrets.php.
 *
 *   php app/bin/bj_smoke.php
 *
 * Lists club info + subscriptions, then checks every BJ subscription name
 * referenced by the pricing catalogue resolves to an ID.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\SubscriptionResolver;
use App\Support\Logger;

$settings = require dirname(__DIR__) . '/config/settings.php';

if (($settings['ballejaune']['api_key'] ?? '') === '') {
    fwrite(STDERR, "Clé API Balle Jaune absente de secrets.php.\n");
    exit(1);
}

$client = new BalleJauneClient(
    $settings['ballejaune']['base_url'],
    $settings['ballejaune']['api_key'],
    new Logger($settings['paths']['log_file']),
);

$club = $client->get('club')['club'] ?? [];
echo "Club : {$club['name']} (id {$club['club_id']})\n\n";

$resolver = new SubscriptionResolver($client);
$map = $resolver->map();
echo "Abonnements Balle Jaune (" . count($map) . ") :\n";
foreach ($map as $name => $id) {
    echo "  {$id}  {$name}\n";
}

$catalogue = require dirname(__DIR__) . '/config/pricing.2025-2026.php';
$needed = array_unique(array_merge(
    array_column($catalogue['formulas'], 'bj_subscription'),
    [$catalogue['ticket_pack']['bj_subscription']],
));

echo "\nRésolution des abonnements requis par le catalogue :\n";
$failures = 0;
foreach ($needed as $name) {
    try {
        $id = $resolver->idForName($name);
        echo "  OK   {$name} → {$id}\n";
    } catch (Throwable $e) {
        echo "  ÉCHEC {$name} — {$e->getMessage()}\n";
        $failures++;
    }
}

exit($failures > 0 ? 1 : 0);
