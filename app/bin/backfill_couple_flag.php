<?php

declare(strict_types=1);

/**
 * One-off backfill: sets BJ's custom2 = '1' for every member currently on a
 * legacy "Couple" subscription, so they still read as a couple once BJ
 * becomes the source of truth for that (see RenewalService::resolveCoupleStatus()).
 * Without this, they'd silently stop reading as couples until their next
 * renewal, since member_formulas has no row for them either.
 *
 * custom3 (partner_bj_user_id) is deliberately left untouched — BJ has never
 * recorded who's paired with whom, and neither has this app, so there is no
 * data to backfill it from. It gets filled in correctly at each member's next
 * renewal (same partner-email UX as today, now also written back to BJ), or
 * an admin can set it by hand in BJ for pairs they already know.
 *
 * Dry-run by default — prints what it would change. Pass --apply to write.
 * Idempotent: members whose custom2 is already '1' are skipped.
 *
 *   php app/bin/backfill_couple_flag.php            dry run
 *   php app/bin/backfill_couple_flag.php --apply     write for real
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\BalleJaune\BalleJauneClient;
use App\Support\Logger;

$settings = require dirname(__DIR__) . '/config/settings.php';

if (($settings['ballejaune']['api_key'] ?? '') === '') {
    fwrite(STDERR, "Clé API Balle Jaune absente de secrets.php.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);

$client = new BalleJauneClient(
    $settings['ballejaune']['base_url'],
    $settings['ballejaune']['api_key'],
    new Logger($settings['paths']['log_file']),
);

$subscriptions = $client->get('subscriptions')['subscriptions'] ?? [];
$coupleSubIds = [];
foreach ($subscriptions as $sub) {
    if (stripos((string) $sub['name'], 'couple') !== false) {
        $coupleSubIds[] = (int) $sub['subscription_id'];
    }
}

if ($coupleSubIds === []) {
    echo "Aucun abonnement « Couple » trouvé dans Balle Jaune — rien à faire.\n";
    exit(0);
}

echo "Abonnements Couple trouvés (" . count($coupleSubIds) . ") :\n";
foreach ($subscriptions as $sub) {
    if (in_array((int) $sub['subscription_id'], $coupleSubIds, true)) {
        echo "  {$sub['subscription_id']}  {$sub['name']}\n";
    }
}
echo "\n";

$filters = json_encode(['subscriptions' => $coupleSubIds]);
$offset = 0;
$toApply = [];
$alreadySet = [];

do {
    $page = $client->get('users', ['filters' => $filters, 'limit' => 200, 'offset' => $offset]);
    $users = $page['users'] ?? [];
    foreach ($users as $user) {
        if (($user['custom2'] ?? '') === '1') {
            $alreadySet[] = $user;
        } else {
            $toApply[] = $user;
        }
    }
    $offset += 200;
} while (count($users) === 200);

echo "Déjà à jour (custom2 = '1') : " . count($alreadySet) . "\n";
echo ($apply ? "Mise à jour" : "À mettre à jour (dry run — relancez avec --apply pour écrire)") . " : " . count($toApply) . "\n\n";

foreach ($toApply as $user) {
    $name = trim($user['firstname'] . ' ' . $user['lastname']);
    if ($apply) {
        $client->patch('users/' . $user['user_id'], ['custom2' => '1']);
        echo "  ÉCRIT  {$user['user_id']}  {$name}\n";
    } else {
        echo "  PRÉVU  {$user['user_id']}  {$name}\n";
    }
}

if (!$apply && $toApply !== []) {
    echo "\nRien n'a été écrit (dry run). Relancez avec --apply pour appliquer.\n";
}
