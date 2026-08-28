<?php

declare(strict_types=1);

/**
 * Deploy-time maintenance control (CLI only) — see the `deploy` skill and
 * App\Middleware\Maintenance.
 *
 *   php app/bin/maintenance.php on
 *       Turns maintenance mode on and invalidates every open session (member
 *       and admin) in one step, so nobody can start a new write while a
 *       deploy is in flight and nobody keeps using a pre-deploy session
 *       once it's over. Idempotent — safe to run again if already on.
 *
 *   php app/bin/maintenance.php off
 *       Turns maintenance mode back off. Does not touch session validity —
 *       everyone forced out by `on` still has to log in again, by design.
 *
 *   php app/bin/maintenance.php wait-clear [--timeout=60] [--interval=2]
 *       Polls orders.status='fulfilling' — the paid→fulfilling→fulfilled
 *       claim is the app's own idempotency lock (see CLAUDE.md), so this is
 *       the one DB-observable "a transaction is actively in flight" signal.
 *       Exits 0 once it's clear, or 1 if it's still non-empty after the
 *       timeout — the caller (deploy.sh) decides what to do with a timeout,
 *       this script never touches the maintenance flag itself.
 *
 *   php app/bin/maintenance.php status
 *       Prints the current maintenance_mode value. Diagnostic only.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

// Must match bootstrap.php's timezone: sessions_invalidated_at is compared
// directly against login_at (AuthService::login()), which is always
// produced through the web app's own bootstrap — a mismatch here would
// silently shift the invalidation cutoff by the timezone offset.
date_default_timezone_set('Europe/Paris');

$settings = require dirname(__DIR__) . '/config/settings.php';
$db = new App\Support\Db($settings['db']);
$logger = new App\Support\Logger($settings['paths']['log_file']);
$settingsRepo = new App\Repository\SettingsRepository($db, $logger);

$command = $argv[1] ?? '';

function cliOption(array $argv, string $name, int $default): int
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return (int) substr($arg, strlen("--{$name}="));
        }
    }
    return $default;
}

switch ($command) {
    case 'on':
        $settingsRepo->set('maintenance_mode', '1');
        $settingsRepo->set('sessions_invalidated_at', (new DateTimeImmutable())->format('Y-m-d H:i:s'));
        echo "Maintenance activée, sessions invalidées.\n";
        exit(0);

    case 'off':
        $settingsRepo->set('maintenance_mode', '0');
        echo "Maintenance désactivée.\n";
        exit(0);

    case 'status':
        echo $settingsRepo->isEnabled('maintenance_mode') ? "on\n" : "off\n";
        exit(0);

    case 'wait-clear':
        $timeout = cliOption($argv, 'timeout', 60);
        $interval = cliOption($argv, 'interval', 2);
        $deadline = time() + $timeout;

        while (true) {
            $stmt = $db->pdo()->query("SELECT COUNT(*) FROM orders WHERE status = 'fulfilling'");
            $inFlight = (int) $stmt->fetchColumn();

            if ($inFlight === 0) {
                echo "Aucune commande en cours de traitement.\n";
                exit(0);
            }

            if (time() >= $deadline) {
                fwrite(STDERR, "Délai dépassé : {$inFlight} commande(s) toujours en statut 'fulfilling' après {$timeout}s.\n");
                exit(1);
            }

            echo "{$inFlight} commande(s) en cours de traitement, nouvelle vérification dans {$interval}s…\n";
            sleep($interval);
        }
        // unreachable

    default:
        fwrite(STDERR, "Usage : php app/bin/maintenance.php <on|off|status|wait-clear> [--timeout=60] [--interval=2]\n");
        exit(1);
}
