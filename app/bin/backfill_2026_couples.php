<?php

declare(strict_types=1);

/**
 * One-off backfill for two couple renewals this season (2026-2027) that hit
 * a bug in RenewalController: a first-time couple change-request only ever
 * resolved partnerBjUserId from $known['partner_bj_user_id'] (an existing
 * member_formulas row), never from the partner_email typed into the
 * change-request form. For a couple's very first renewal as a couple,
 * $known doesn't exist yet, so partnerBjUserId silently stayed 0 even though
 * isCouple was true — fixed going forward in RenewalController (the approved-
 * change-request branch now falls back to resolving partner_email the same
 * way the direct path already did).
 *
 * That meant FulfillmentService::fulfillRenewal() only ever saw one user in
 * $userIds, so:
 *   - the payer's BJ record got the *full* order amount as
 *     subscription_paid_amount instead of their half, and no custom3
 *     (partner link), and their subscription_notes never got the
 *     "Couple — total réglé … pour 2" line.
 *   - the partner's BJ record never got touched at all (still shows last
 *     season's subscription_date_end/paid info), and never got a
 *     member_formulas row.
 *
 * Affects order #134 (Adib Apandi / Oumaïma Youssoufi, Heures Pleines) and
 * order #117 (Jonathan Moutet / Miaomiao Li, Heures Creuses). This script
 * mirrors exactly what fulfillRenewal() would have written for the partner,
 * reading the payer's already-correct subscription_id/date_end/flag off
 * their live BJ record rather than recomputing via PricingService (so it
 * can't drift from what the payer actually got), corrects the payer's
 * over-attributed paid_amount, links custom2/custom3 both ways, backfills
 * the local member_formulas rows, and fixes each order's stored meta so
 * partnerBjUserId reflects reality.
 *
 * Dry-run by default — prints every read and every intended write without
 * touching BJ or the database. Pass --apply to write for real. Not safely
 * re-runnable with --apply after a successful apply (subscription_notes
 * would be prepended a second time) — check the dry-run output's "already
 * applied?" line, which compares against the current live BJ state.
 *
 *   php app/bin/backfill_2026_couples.php            dry run
 *   php app/bin/backfill_2026_couples.php --apply     write for real
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
if (($settings['db']['name'] ?? '') === '') {
    fwrite(STDERR, "Configuration base de données absente de secrets.php.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);

$bj = new BalleJauneClient(
    $settings['ballejaune']['base_url'],
    $settings['ballejaune']['api_key'],
    new Logger($settings['paths']['log_file']),
);

$db = $settings['db'];
$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
    $db['user'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

/**
 * @param array{orderId:int, payerId:int, partnerId:int, partnerLicenceLabel:string, partnerLicenceRemoved:bool} $case
 */
function processCase(array $case, PDO $pdo, BalleJauneClient $bj, bool $apply): void
{
    $orderId = $case['orderId'];

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($order === false) {
        echo "ORDER #$orderId introuvable — abandon de ce cas.\n\n";
        return;
    }
    $meta = json_decode((string) $order['meta'], true) ?: [];

    $payer = $bj->get('users/' . $case['payerId'])['user'];
    $partner = $bj->get('users/' . $case['partnerId'])['user'];
    $payerName = trim($payer['firstname'] . ' ' . $payer['lastname']);
    $partnerName = trim($partner['firstname'] . ' ' . $partner['lastname']);

    echo "=== Commande #$orderId — {$payerName} & {$partnerName} ===\n";
    echo "Montant total réglé : " . number_format((float) $order['amount'], 2, ',', ' ') . " €\n";

    if ((int) ($payer['custom3'] ?? 0) === $case['partnerId'] && (int) ($partner['custom3'] ?? 0) === $case['payerId']) {
        echo "Déjà appliqué (custom3 déjà croisé des deux côtés) — rien à faire.\n\n";
        return;
    }

    $count = 2;
    $shareCents = (int) round((float) $order['amount'] * 100 / $count);
    $partnerShare = $shareCents / 100;
    $payerShare = ((float) $order['amount'] * 100 - $shareCents) / 100;

    $seasonLabel = $case['seasonLabel'];
    $fulfilledDate = (new DateTimeImmutable((string) $order['fulfilled_at']))->format('Y-m-d');

    $notesFor = function (bool $forPartner) use ($order, $meta, $case, $seasonLabel): string {
        $notes = 'Renouvellement en ligne #' . $order['id']
            . (!empty($meta['transactionCode']) ? ' (' . $meta['transactionCode'] . ')' : '')
            . ' — ' . $case['formulaLabel']
            . ' | saison ' . $seasonLabel
            . ' | Couple — total réglé ' . number_format((float) $order['amount'], 2, ',', ' ') . ' € pour 2'
            . ((int) ($meta['lessons'] ?? 0) > 0 ? ' | Cours collectifs × ' . (int) $meta['lessons'] : '');
        if ($forPartner) {
            $notes .= $case['partnerLicenceRemoved']
                ? ' | Licence retirée — motif : ' . $case['partnerLicenceRemovalReason']
                : ' | Licence : ' . $case['partnerLicenceLabel'];
        }
        return $notes;
    };

    $partnerNote = $notesFor(true);
    $partnerExistingNotes = trim((string) ($partner['subscription_notes'] ?? ''));
    $partnerCombinedNotes = mb_substr(
        $partnerExistingNotes !== '' ? $partnerNote . "\n" . $partnerExistingNotes : $partnerNote,
        0,
        1000,
    );

    $payerCorrectionNote = '[Backfill ' . date('Y-m-d') . '] Lien couple confirmé avec ' . $partnerName
        . ' (id ' . $case['partnerId'] . ') — part corrigée à ' . number_format($payerShare, 2, ',', ' ')
        . ' € (total ' . number_format((float) $order['amount'], 2, ',', ' ') . ' € pour 2, réglé sur ce compte). Voir #' . $orderId . '.';
    $payerExistingNotes = trim((string) ($payer['subscription_notes'] ?? ''));
    $payerCombinedNotes = mb_substr($payerCorrectionNote . "\n" . $payerExistingNotes, 0, 1000);

    $partnerPatch = [
        'subscription_id'          => (int) $payer['subscription_id'],
        'subscription_date_end'    => $payer['subscription_date_end'],
        'subscription_paid'        => true,
        'subscription_paid_date'   => $fulfilledDate,
        'subscription_paid_amount' => $partnerShare,
        'subscription_notes'       => $partnerCombinedNotes,
        'flag'                     => true,
        'custom2'                  => '1',
        'custom3'                  => (string) $case['payerId'],
    ];
    $partnerStart = (string) ($partner['subscription_date_start'] ?? '');
    if ($partnerStart === '' || $partnerStart === '0000-00-00') {
        $partnerPatch['subscription_date_start'] = $payer['subscription_date_start'];
    }

    $payerPatch = [
        'subscription_paid_amount' => $payerShare,
        'subscription_notes'       => $payerCombinedNotes,
        'custom2'                  => '1',
        'custom3'                  => (string) $case['partnerId'],
    ];

    echo "\n-- BJ user {$case['partnerId']} ({$partnerName}) — actuellement subscription_date_end={$partner['subscription_date_end']} paid_amount={$partner['subscription_paid_amount']} custom2=" . ($partner['custom2'] ?? '') . " custom3=" . ($partner['custom3'] ?? '') . " --\n";
    foreach ($partnerPatch as $k => $v) {
        echo "  $k => " . (is_string($v) && mb_strlen($v) > 80 ? mb_substr($v, 0, 80) . '…' : var_export($v, true)) . "\n";
    }

    echo "\n-- BJ user {$case['payerId']} ({$payerName}) — actuellement paid_amount={$payer['subscription_paid_amount']} custom2=" . ($payer['custom2'] ?? '') . " custom3=" . ($payer['custom3'] ?? '') . " --\n";
    foreach ($payerPatch as $k => $v) {
        echo "  $k => " . (is_string($v) && mb_strlen($v) > 80 ? mb_substr($v, 0, 80) . '…' : var_export($v, true)) . "\n";
    }

    echo "\n-- member_formulas --\n";
    echo "  INSERT saison {$case['seasonStartYear']} bj_user_id={$case['partnerId']} subscription_type={$case['subscriptionType']} is_couple=1 competitor=" . ((int) $case['partnerCompetitor']) . " lessons=" . (int) ($meta['lessons'] ?? 0) . " partner_bj_user_id={$case['payerId']} order_id=$orderId\n";
    echo "  UPDATE saison {$case['seasonStartYear']} bj_user_id={$case['payerId']} SET partner_bj_user_id={$case['partnerId']}\n";

    echo "\n-- orders.meta --\n";
    echo "  order #$orderId: partnerBjUserId 0 -> {$case['partnerId']}\n\n";

    if (!$apply) {
        return;
    }

    $bj->patch('users/' . $case['partnerId'], $partnerPatch);
    echo "  ÉCRIT BJ {$case['partnerId']}\n";
    $bj->patch('users/' . $case['payerId'], $payerPatch);
    echo "  ÉCRIT BJ {$case['payerId']}\n";

    $ins = $pdo->prepare(
        'INSERT INTO member_formulas (season_start_year, bj_user_id, subscription_type, is_couple, competitor, lessons, partner_bj_user_id, order_id, pricing_residence, created_at)
         VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE subscription_type = VALUES(subscription_type), is_couple = 1, competitor = VALUES(competitor), lessons = VALUES(lessons), partner_bj_user_id = VALUES(partner_bj_user_id), order_id = VALUES(order_id), pricing_residence = VALUES(pricing_residence)',
    );
    $ins->execute([
        $case['seasonStartYear'],
        $case['partnerId'],
        $case['subscriptionType'],
        (int) $case['partnerCompetitor'],
        (int) ($meta['lessons'] ?? 0),
        $case['payerId'],
        $orderId,
        $order['pricing_residence'],
        $order['fulfilled_at'],
    ]);
    echo "  ÉCRIT member_formulas pour {$case['partnerId']}\n";

    $upd = $pdo->prepare('UPDATE member_formulas SET partner_bj_user_id = ? WHERE season_start_year = ? AND bj_user_id = ?');
    $upd->execute([$case['partnerId'], $case['seasonStartYear'], $case['payerId']]);
    echo "  MIS À JOUR member_formulas pour {$case['payerId']} (partner_bj_user_id = {$case['partnerId']})\n";

    $meta['partnerBjUserId'] = $case['partnerId'];
    $updOrder = $pdo->prepare('UPDATE orders SET meta = ? WHERE id = ?');
    $updOrder->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $orderId]);
    echo "  MIS À JOUR orders.meta pour #$orderId\n\n";
}

$cases = [
    [
        'orderId'                     => 134,
        'payerId'                     => 1544420, // Adib Apandi
        'partnerId'                   => 1550044, // Oumaïma Youssoufi
        'seasonStartYear'             => 2026,
        'seasonLabel'                 => '2026-2027',
        'formulaLabel'                => 'Heures Pleines',
        'subscriptionType'            => 'heures-pleines',
        'partnerCompetitor'           => true,
        'partnerLicenceRemoved'       => false,
        'partnerLicenceRemovalReason' => '',
        'partnerLicenceLabel'         => 'fédérale',
    ],
    [
        'orderId'                     => 117,
        'payerId'                     => 2013726, // Jonathan Moutet
        'partnerId'                   => 2013731, // Miaomiao Li
        'seasonStartYear'             => 2026,
        'seasonLabel'                 => '2026-2027',
        'formulaLabel'                => 'Heures Creuses',
        'subscriptionType'            => 'heures-creuses',
        'partnerCompetitor'           => false,
        'partnerLicenceRemoved'       => false,
        'partnerLicenceRemovalReason' => '',
        'partnerLicenceLabel'         => 'Pass',
    ],
];

echo $apply ? "MODE ÉCRITURE\n\n" : "MODE DRY-RUN (aucune écriture — relancez avec --apply)\n\n";

foreach ($cases as $case) {
    processCase($case, $pdo, $bj, $apply);
}
