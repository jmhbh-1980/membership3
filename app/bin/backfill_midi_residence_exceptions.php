<?php

declare(strict_types=1);

/**
 * One-off: turns the old silent Midi grandfather into explicit, attributed
 * residence exceptions.
 *
 * Until residence exceptions existed, RenewalController quietly granted the
 * Garennois grid to any Hors-commune member whose current subscription was
 * Midi — Midi has no hors-commune price bucket, so without that they could not
 * have renewed at all. It had no reason, no author, and no end: exactly the
 * invisible permanent right the per-season exception model exists to prevent.
 *
 * This script writes one residence_exceptions row per affected member for a
 * single season, so their next renewal prices identically to before but is now
 * visible in /admin/exceptions-tarif. From the season after that they appear in
 * the re-grant list like everyone else, and the club decides deliberately.
 *
 * Run it once per season boundary for as long as the grandfathered members are
 * still around, or once and then let the exceptions lapse — that is the point.
 *
 *   php app/bin/backfill_midi_residence_exceptions.php --season=2026
 *   ... add --apply once the dry-run output looks right.
 *
 * Options:
 *   --season=YYYY   season start year to grant for (default: the current season)
 *   --apply         write for real (default: dry run)
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Repository\ResidenceExceptionRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\SubscriptionResolver;
use App\Service\PricingService;
use App\Service\Season;
use App\Support\Db;
use App\Support\Logger;

date_default_timezone_set('Europe/Paris');

$settings = require dirname(__DIR__) . '/config/settings.php';

$apply = in_array('--apply', $argv, true);
$seasonArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--season=')) {
        $seasonArg = (int) substr($arg, strlen('--season='));
    }
}
$season = $seasonArg !== null ? new Season($seasonArg) : Season::fromDate(new DateTimeImmutable());

$db = new Db($settings['db']);
$logger = new Logger($settings['paths']['log_file']);
$bj = new BalleJauneClient(
    $settings['ballejaune']['base_url'],
    $settings['ballejaune']['api_key'] ?? '',
    $logger,
);
$pricing = new PricingService($settings['paths']['pricing_data'], $settings['club']['city_zip']);
$subscriptions = new SubscriptionResolver($bj);
$exceptions = new ResidenceExceptionRepository($db);

$midiBjName = $pricing->subscription('midi', $season)['bj_subscription'];
$namesById = array_flip($subscriptions->map());

echo ($apply ? 'Application' : 'Simulation (--apply pour écrire)')
    . " — saison {$season->label()}, abonnement « {$midiBjName} »\n\n";

$reason = 'Reconduction — abonné Midi hors commune avant le passage aux exceptions explicites '
    . '(l\'ancien report automatique appliquait déjà le tarif Garennois).';

$offset = 0;
$granted = 0;
$skipped = 0;

do {
    $data = $bj->get('users', ['limit' => 200, 'offset' => $offset]);
    $users = $data['users'] ?? [];

    foreach ($users as $user) {
        if (($namesById[(int) $user['subscription_id']] ?? '') !== $midiBjName) {
            continue;
        }
        if ($pricing->residenceForZip((string) ($user['postalcode'] ?? '')) !== PricingService::RESIDENCE_HORS_COMMUNE) {
            continue; // an actual resident on Midi needs no exception
        }

        $bjUserId = (int) $user['user_id'];
        $name = trim(($user['lastname'] ?? '') . ' ' . ($user['firstname'] ?? ''));

        // Never clobber a decision an admin already made for this season,
        // in either direction — a revoked row means they said no.
        if ($exceptions->find($season->startYear, $bjUserId) !== null) {
            echo "  = {$name} (#{$bjUserId}) — décision déjà enregistrée, ignoré\n";
            $skipped++;
            continue;
        }

        echo "  + {$name} (#{$bjUserId}) — tarif Garennois\n";
        if ($apply) {
            $exceptions->grant(
                $season->startYear,
                $bjUserId,
                PricingService::RESIDENCE_GARENNOIS,
                $reason,
                'backfill_midi_residence_exceptions.php',
            );
        }
        $granted++;
    }

    $offset += 200;
    $total = (int) ($data['total'] ?? 0);
} while ($offset < $total && $users !== []);

echo "\n" . ($apply ? 'Accordées' : 'À accorder') . " : {$granted}, ignorées : {$skipped}.\n";
if (!$apply && $granted > 0) {
    echo "Relancez avec --apply pour écrire.\n";
}
