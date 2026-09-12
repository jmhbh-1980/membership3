<?php

declare(strict_types=1);

/**
 * Cron script (once daily) — attempts any installment whose due date has
 * arrived. One attempt only, no retry: this app's decision is that a
 * failed/declined auto-charge lapses the plan and emails the member a
 * manual pay-now link, rather than chasing it automatically (this also
 * absorbs SumUp's undocumented behaviour for an unattended 3DS challenge —
 * whatever the reason a charge didn't succeed, the member gets the same
 * fallback). A charge that comes back still PENDING (a genuinely async
 * SumUp state, not a decline) is left for the next run to re-check — that's
 * re-verifying an already-attempted charge, not re-charging, so it doesn't
 * break the no-retry rule.
 *
 *   php app/bin/charge-installments.php [--dry-run]
 *
 * No shared bootstrap.php reuse here (unlike the web app) — this script
 * hand-wires its own dependency graph, same convention as migrate.php and
 * maintenance.php, just a longer list since fulfillment needs the full
 * invoicing chain too.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('Europe/Paris');

$settings = require dirname(__DIR__) . '/config/settings.php';
$dryRun = in_array('--dry-run', $argv, true);

$db = new App\Support\Db($settings['db']);
$logger = new App\Support\Logger($settings['paths']['log_file']);
$settingsRepo = new App\Repository\SettingsRepository($db, $logger);

$installmentPlans = new App\Repository\InstallmentPlanRepository($db);
$orders = new App\Repository\OrderRepository($db);
$applications = new App\Repository\ApplicationRepository($db);
$auditLog = new App\Repository\AuditLogRepository($db);
$invoiceRepo = new App\Repository\InvoiceRepository($db);
$promoCodeRepo = new App\Repository\PromoCodeRepository($db);
$residenceExceptions = new App\Repository\ResidenceExceptionRepository($db);

$bj = new App\Service\BalleJaune\BalleJauneClient($settings['ballejaune']['base_url'], $settings['ballejaune']['api_key'] ?? '', $logger);
$subscriptions = new App\Service\BalleJaune\SubscriptionResolver($bj);
$roles = new App\Service\BalleJaune\RoleResolver($bj);
$pricing = new App\Service\PricingService($settings['paths']['pricing_data'], $settings['club']['city_zip']);
$renewals = new App\Service\RenewalService($db, $pricing);
$mailer = new App\Service\Mailer($settings['smtp'], $db, $logger, $settingsRepo);
$sumup = new App\Service\SumUpService($settings['sumup'], $logger);
$checkoutDescription = new App\Service\CheckoutDescription(
    new App\Service\CheckoutAbbreviations($settings['paths']['pricing_data']),
);

$bankDetails = new App\Service\BankDetailsService($settingsRepo, $settings['club']['bank'] ?? []);
$invoicePdf = new App\Service\InvoicePdfService($settings['paths']['uploads'], $settings['club'], dirname(__DIR__) . '/assets/logo.png', $bankDetails);
$invoiceDescriptions = new App\Service\InvoiceDescriptions($settings['paths']['pricing_data']);
$invoiceLineComposer = new App\Service\InvoiceLineComposer($pricing, $invoiceDescriptions);
$orderBreakdown = new App\Service\OrderBreakdownService($promoCodeRepo);
$invoiceNumbers = new App\Service\InvoiceNumberService($db);
$invoices = new App\Service\InvoiceService($invoiceRepo, $invoiceNumbers, $orderBreakdown, $invoiceLineComposer, $invoicePdf, $settings['paths']['uploads'], $logger);

$fulfillment = new App\Service\FulfillmentService(
    $applications, $orders, $bj, $subscriptions, $roles, $pricing, $renewals, $mailer, $logger, $invoices, $auditLog, $installmentPlans,
    $residenceExceptions,
);
$settlement = new App\Service\PaymentSettlementService($orders, $sumup, $fulfillment, $logger, $auditLog, $installmentPlans);

$today = (new DateTimeImmutable())->format('Y-m-d');
$attempted = 0;
$charged = 0;
$failed = 0;
$pending = 0;

// Delimits each run in app_logs/installments-cron.log, which the crontab appends
// to forever — without this, a mailed tail is a wall of undated lines.
echo '=== ' . (new DateTimeImmutable())->format('Y-m-d H:i:s')
    . ($dryRun ? ' — dry-run' : '') . " ===\n";

foreach ($installmentPlans->allActive() as $plan) {
    $schedule = json_decode((string) $plan['schedule'], true) ?: [];
    $dueEntry = null;
    // Schedule is stored in ascending installment-number order — the first
    // still-pending entry is always the next one due.
    foreach ($schedule as $entry) {
        if ($entry['status'] === 'pending') {
            $dueEntry = $entry;
            break;
        }
    }
    if ($dueEntry === null || $dueEntry['due_date'] > $today) {
        continue;
    }

    $number = (int) $dueEntry['number'];
    $attempted++;
    echo "Plan #{$plan['id']} (bj_user_id {$plan['bj_user_id']}) — échéance {$number}/{$plan['installment_count']}, "
        . number_format((float) $dueEntry['amount'], 2, ',', ' ') . " € due le {$dueEntry['due_date']}\n";
    if ($dryRun) {
        continue;
    }

    // Idempotent across runs: an order already exists for this
    // (plan, number) if a previous run got interrupted after creating it —
    // resume verifying that one instead of charging a second time.
    $stmt = $db->pdo()->prepare('SELECT * FROM orders WHERE installment_plan_id = ? AND installment_number = ?');
    $stmt->execute([$plan['id'], $number]);
    $order = $stmt->fetch() ?: null;

    if ($order === null) {
        $user = $bj->get('users/' . $plan['bj_user_id'])['user'];
        $intent = json_decode((string) $plan['renewal_intent'], true) ?: [];
        // Both taken from the plan's frozen intent, not re-derived: installment
        // 2+ must record the same residence the schedule was priced at, even if
        // the member moved or their exception was revoked in the meantime.
        $residence = (string) ($intent['residence'] ?? '');
        $order = $orders->create(
            'renewal',
            null,
            (int) $plan['bj_user_id'],
            (string) $user['email'],
            (float) $dueEntry['amount'],
            $dueEntry['lines'],
            $intent,
            residence: $residence,
            pricingResidence: (string) ($intent['pricingResidence'] ?? $residence),
        );
        $orders->update((int) $order['id'], ['installment_plan_id' => $plan['id'], 'installment_number' => $number]);
    }

    if ($order['checkout_id'] === '' || $order['checkout_id'] === null) {
        try {
            $result = $sumup->chargeToken(
                $order['checkout_reference'],
                (float) $dueEntry['amount'],
                $checkoutDescription->forOrder(
                    $order,
                    "Renouvellement Bad & Squash — versement {$number}/{$plan['installment_count']}",
                ),
                (string) $plan['sumup_customer_id'],
                (string) $plan['sumup_payment_token'],
            );
            if ($result['checkout_id'] !== '') {
                $orders->update((int) $order['id'], ['checkout_id' => $result['checkout_id']]);
            }
        } catch (\RuntimeException $e) {
            $logger->error('installments', 'Charge attempt failed', [
                'plan_id' => $plan['id'], 'number' => $number, 'error' => $e->getMessage(),
            ]);
        }
        $order = $orders->findById((int) $order['id']);
    }

    if ($order['checkout_id'] !== '') {
        $settlement->settle($order);
        $order = $orders->findById((int) $order['id']);
    }

    if ($order['status'] === 'fulfilled') {
        $charged++;
        echo "  réglée (commande #{$order['id']}).\n";
        continue;
    }

    if ($order['status'] !== 'failed' && $order['checkout_id'] !== '') {
        // Genuinely still pending on SumUp's side (not a decline) — leave
        // it for tomorrow's run to re-check, not a retry of the charge itself.
        $pending++;
        echo "  encore en attente de confirmation SumUp (commande #{$order['id']}) — nouvelle vérification au prochain passage.\n";
        continue;
    }

    // Declined, or the charge attempt itself never got a checkout_id at
    // all — lapse the plan and tell the member how to catch up manually.
    foreach ($schedule as $i => $entry) {
        if ((int) $entry['number'] === $number) {
            $schedule[$i]['status'] = 'failed';
            break;
        }
    }
    $installmentPlans->updateSchedule((int) $plan['id'], $schedule);
    $installmentPlans->markStatus((int) $plan['id'], 'lapsed');
    $auditLog->log('system', 'installment.failed', 'installment_plan', (string) $plan['id'], [
        'number' => $number, 'amount' => $dueEntry['amount'],
    ]);

    $user = $bj->get('users/' . $plan['bj_user_id'])['user'];
    $mailer->send(
        (string) $user['email'],
        'Le prélèvement de votre versement a échoué — Bad & Squash',
        '<p>Bonjour,</p><p>Le prélèvement automatique de votre versement de '
        . number_format((float) $dueEntry['amount'], 2, ',', ' ') . ' € n\'a pas abouti.</p>'
        . '<p>Merci de contacter le club pour régulariser votre paiement.</p>',
        'installment_failed',
    );
    $failed++;
    echo "  échec — plan marqué 'lapsed', email envoyé.\n";
}

echo "\n{$attempted} échéance(s) examinée(s)"
    . ($dryRun
        ? ' (dry-run, rien exécuté).'
        : ", {$charged} réglée(s), {$failed} échouée(s), {$pending} en attente de confirmation.")
    . "\n";

// The exit code is this script's alert channel. Cron mails a job's output, and the
// crontab prints the log tail only when this exits non-zero, so anything the club
// must hear about has to be signalled here rather than merely logged.
//
// A declined charge counts. It is already handled correctly above — the plan is
// marked 'lapsed' and the member emailed — but it still needs a human: the member's
// Balle Jaune coverage stops extending at that due date (FulfillmentService writes
// subscription_date_end from the entry's extends_to), so they lose court access
// while believing they are paid up.
//
// A charge still awaiting SumUp confirmation deliberately does not count: the next
// run re-checks it, and alerting on it would mail the club every day of a slow
// settlement. One that never resolves is a gap this exit code does not cover.
//
//     0  nothing was due, or every due charge settled
//     2  ran to completion, but at least one charge failed
//   255  PHP fatal — PHP's own code, not set here
exit($failed > 0 ? 2 : 0);
