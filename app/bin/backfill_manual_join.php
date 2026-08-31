<?php

declare(strict_types=1);

/**
 * One-off reconciliation: backfills a member's local records — member_formulas,
 * lesson_enrollments, an `orders` row, and an invoice — for a season they
 * joined and paid for directly through the club (espèces/chèque/virement)
 * before this app existed, so they never went through the wizard here.
 *
 * Balle Jaune already has this member as active for the season — that's not
 * in question, RenewalService::subscriptionCovers() reads BJ's own
 * subscription_date_end directly, so the renewal page already shows them as
 * paid, and this script never writes to BJ. What's missing is everything this
 * app itself owns and BJ has no field for: licence kind
 * (member_formulas.competitor), cours collectifs enrollment
 * (lesson_enrollments), and a proper invoice.
 *
 * The order is recorded as kind='renewal' rather than 'join', even though
 * this is genuinely a first membership (priced with premiere: true below) —
 * every "which orders belong to this member" lookup in this codebase (the
 * member's own "Mes factures" page, the admin dashboard's paid-date column,
 * the invoice manual-recovery button) matches a join order through
 * application_id -> application_people, which doesn't exist here. A renewal
 * order is matched directly by bj_user_id everywhere, which does exist.
 * Nothing downstream (invoice wording, cart line labels) is derived from
 * `kind` itself — see app/src/Service/InvoiceLineComposer.php.
 *
 * Dry-run by default — prints exactly what would be written. Pass --apply to
 * commit (one DB transaction). Idempotent: refuses to run twice for the same
 * member+season unless --force (member_formulas is a REPLACE INTO — a second
 * run would silently orphan the first order rather than erroring).
 *
 * The computed price (via the same PricingService used everywhere else) must
 * match --amount exactly — this never lets you force through an arbitrary
 * discount, since that would leave cart_lines/orders.amount inconsistent
 * (an invariant every other order-creation path in this app relies on). If
 * the club genuinely agreed a different price, enter that order by hand
 * instead of using this script.
 *
 *   php app/bin/backfill_manual_join.php \
 *     --bj-user-id=12345 --subscription-type=heures-pleines --residence=garennois \
 *     --join-date=2026-10-15 --amount=239.00 \
 *     --payment-date=2026-09-20 --payment-method=especes
 *
 *   ... add --apply once the dry-run output looks right.
 *
 * Options:
 *   --bj-user-id=INT           required — Balle Jaune user id (identity/billing are read from BJ)
 *   --subscription-type=KEY    required — heures-pleines | heures-creuses | midi | jeune
 *   --residence=KEY            required — garennois | hors-commune
 *   --season=YYYY               default: current season
 *   --join-date=YYYY-MM-DD     required — actual date they joined (drives prorata)
 *   --amount=DECIMAL           required — amount actually collected; must match the computed price
 *   --payment-date=YYYY-MM-DD  required — for the audit trail (order meta), not the order's created_at
 *   --payment-method=KEY       required — especes | cheque | virement | autre
 *   --competitor                flag — drives fédérale vs pass (ignored for jeune)
 *   --couple                    flag — needs --partner-bj-user-id
 *   --partner-bj-user-id=INT   required if --couple
 *   --partner-competitor        flag — partner's licence kind
 *   --lessons=N                 default 0 — cours collectifs slots (0-2, couples only get 2)
 *   --midi-residency-override   flag
 *   --summer-pack                flag — Pack été (solo only, no lessons)
 *   --no-invoice                 skip invoice/PDF generation
 *   --apply                      write for real (default: dry run)
 *   --force                      bypass the idempotency guard only (not the price check)
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Repository\OrderRepository;
use App\Repository\PromoCodeRepository;
use App\Repository\SettingsRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BankDetailsService;
use App\Service\InvoiceDescriptions;
use App\Service\InvoiceLineComposer;
use App\Service\InvoiceNumberService;
use App\Service\InvoicePdfService;
use App\Service\InvoiceService;
use App\Service\OrderBreakdownService;
use App\Service\PricingService;
use App\Service\RenewalService;
use App\Service\Season;
use App\Support\Db;
use App\Support\Logger;

date_default_timezone_set('Europe/Paris');

function opt(array $argv, string $name): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen("--{$name}="));
        }
    }
    return null;
}

function hasFlag(array $argv, string $name): bool
{
    return in_array("--{$name}", $argv, true);
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// ── Argument parsing ─────────────────────────────────────────────────────

$bjUserId = (int) (opt($argv, 'bj-user-id') ?? 0);
$subscriptionType = opt($argv, 'subscription-type') ?? '';
$residence = opt($argv, 'residence') ?? '';
$seasonStartYear = (int) (opt($argv, 'season') ?? Season::fromDate(new DateTimeImmutable())->startYear);
$joinDateOpt = opt($argv, 'join-date') ?? '';
$amountOpt = opt($argv, 'amount');
$paymentDateOpt = opt($argv, 'payment-date') ?? '';
$paymentMethodKey = opt($argv, 'payment-method') ?? '';
$competitor = hasFlag($argv, 'competitor');
$isCouple = hasFlag($argv, 'couple');
$partnerBjUserId = (int) (opt($argv, 'partner-bj-user-id') ?? 0);
$partnerCompetitor = hasFlag($argv, 'partner-competitor');
$lessons = (int) (opt($argv, 'lessons') ?? 0);
$midiOverride = hasFlag($argv, 'midi-residency-override');
$summerPack = hasFlag($argv, 'summer-pack');
$skipInvoice = hasFlag($argv, 'no-invoice');
$apply = hasFlag($argv, 'apply');
$force = hasFlag($argv, 'force');

if ($bjUserId <= 0) {
    fail('--bj-user-id est requis.');
}
if (!in_array($subscriptionType, ['heures-pleines', 'heures-creuses', 'midi', 'jeune'], true)) {
    fail('--subscription-type invalide (heures-pleines|heures-creuses|midi|jeune).');
}
if (!in_array($residence, [PricingService::RESIDENCE_GARENNOIS, PricingService::RESIDENCE_HORS_COMMUNE], true)) {
    fail('--residence invalide (garennois|hors-commune).');
}
$joinDate = DateTimeImmutable::createFromFormat('Y-m-d', $joinDateOpt);
if ($joinDate === false) {
    fail('--join-date invalide (attendu YYYY-MM-DD).');
}
if ($amountOpt === null || !is_numeric($amountOpt)) {
    fail('--amount est requis (nombre décimal).');
}
$amount = round((float) $amountOpt, 2);
$paymentDate = DateTimeImmutable::createFromFormat('Y-m-d', $paymentDateOpt);
if ($paymentDate === false) {
    fail('--payment-date invalide (attendu YYYY-MM-DD).');
}
$paymentMethodLabels = ['especes' => 'Espèces', 'cheque' => 'Chèque', 'virement' => 'Virement bancaire', 'autre' => 'Autre'];
if (!isset($paymentMethodLabels[$paymentMethodKey])) {
    fail('--payment-method invalide (especes|cheque|virement|autre).');
}
if ($isCouple && $partnerBjUserId <= 0) {
    fail('--couple nécessite --partner-bj-user-id.');
}

// ── Wiring (no DI container in bin scripts — see bin/maintenance.php) ────

$settings = require dirname(__DIR__) . '/config/settings.php';
if (($settings['ballejaune']['api_key'] ?? '') === '') {
    fail('Clé API Balle Jaune absente de secrets.php.');
}

$db = new Db($settings['db']);
$logger = new Logger($settings['paths']['log_file']);
$bj = new BalleJauneClient($settings['ballejaune']['base_url'], $settings['ballejaune']['api_key'], $logger);
$pricing = new PricingService($settings['paths']['pricing_data'], $settings['club']['city_zip']);
$orders = new OrderRepository($db);
$renewals = new RenewalService($db, $pricing);

$season = new Season($seasonStartYear);

// ── Fetch member identity/billing from BJ ─────────────────────────────────

$bjUser = $bj->get('users/' . $bjUserId)['user'] ?? null;
if ($bjUser === null) {
    fail("Membre introuvable dans Balle Jaune (user_id {$bjUserId}).");
}
$partnerBjUser = null;
if ($isCouple) {
    $partnerBjUser = $bj->get('users/' . $partnerBjUserId)['user'] ?? null;
    if ($partnerBjUser === null) {
        fail("Partenaire introuvable dans Balle Jaune (user_id {$partnerBjUserId}).");
    }
}

echo "Membre : {$bjUser['firstname']} {$bjUser['lastname']} <{$bjUser['email']}> (user_id {$bjUserId})\n";
echo '  Abonnement BJ actuel jusqu\'au : ' . ($bjUser['subscription_date_end'] ?? '?') . "\n";
if ($partnerBjUser !== null) {
    echo "Partenaire : {$partnerBjUser['firstname']} {$partnerBjUser['lastname']} (user_id {$partnerBjUserId})\n";
}
echo "\n";

// ── Idempotency guard ──────────────────────────────────────────────────────

$check = $db->pdo()->prepare('SELECT order_id FROM member_formulas WHERE season_start_year = ? AND bj_user_id = ?');
$check->execute([$seasonStartYear, $bjUserId]);
$existingOrderId = $check->fetchColumn();
if ($existingOrderId !== false && !$force) {
    fail("Une fiche member_formulas existe déjà pour ce membre pour la saison {$season->label()} (order_id={$existingOrderId}). Relancez avec --force pour écraser.");
}

// ── Price the membership (same engine as a real join) ─────────────────────

$people = $isCouple
    ? [
        ['competitor' => $competitor, 'licenceRemoved' => false],
        ['competitor' => $partnerCompetitor, 'licenceRemoved' => false],
    ]
    : [['competitor' => $competitor, 'licenceRemoved' => false]];

try {
    $quote = $pricing->quote(
        $subscriptionType,
        $residence,
        premiere: true,
        season: $season,
        joinDate: $joinDate,
        isCouple: $isCouple,
        people: $people,
        lessonsCount: $lessons,
        midiResidencyOverride: $midiOverride,
        summerPack: $summerPack,
        studentDiscount: false,
        promo: null,
    );
} catch (InvalidArgumentException $e) {
    fail('Erreur de tarification : ' . $e->getMessage());
}

$computedTotal = $quote->total();
echo "Panier calculé :\n";
foreach ($quote->lines as $line) {
    printf("  %-10s %-55s %8.2f €\n", $line->type, $line->label, $line->amount);
}
printf("  %-66s %8.2f €\n", 'TOTAL', $computedTotal);
echo "\n";

if (abs($computedTotal - $amount) > 0.01) {
    fail(sprintf(
        "Le montant calculé (%.2f €) ne correspond pas au montant réellement encaissé (%.2f €). ".
        "Vérifiez les options passées (formule, résidence, date d'adhésion pour le prorata, cours collectifs…). ".
        "Si le club a réellement accordé un tarif différent, saisissez cette commande à la main plutôt que d'utiliser ce script.",
        $computedTotal,
        $amount,
    ));
}

// ── Build the records ──────────────────────────────────────────────────────

$cartLines = array_map(fn ($l) => [
    'type' => $l->type, 'label' => $l->label, 'amount' => $l->amount,
    'baseAmount' => $l->baseAmount, 'personIndex' => $l->personIndex,
], $quote->lines);

$meta = [
    'subscriptionType' => $subscriptionType,
    'isCouple' => $isCouple,
    'seasonStartYear' => $seasonStartYear,
    'residence' => $residence,
    'competitor' => $competitor,
    'partnerCompetitor' => $partnerCompetitor,
    'partnerBjUserId' => $partnerBjUserId,
    'lessons' => $lessons,
    'lateSettlement' => $summerPack,
    'licenceRemoved' => false,
    'partnerLicenceRemoved' => false,
    'manualReconciliation' => true,
    'manualReconciliationReason' => "Adhésion conclue et réglée directement auprès du club avant la mise en ligne de l'application (saison {$season->label()}).",
    'actualJoinDate' => $joinDate->format('Y-m-d'),
    'actualPaymentDate' => $paymentDate->format('Y-m-d'),
    'actualPaymentMethod' => $paymentMethodLabels[$paymentMethodKey],
    'backfilledBy' => get_current_user() ?: 'cli',
    'backfilledAt' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
];

echo 'Saison : ' . $season->label() . ($summerPack ? ' (Pack été)' : '') . "\n";
echo "Formule : {$subscriptionType} / {$residence}" . ($competitor ? ' / compétiteur' : '') . "\n";
echo "Cours collectifs : {$lessons}\n";
echo 'Paiement réel : ' . number_format($amount, 2, ',', ' ') . " € — {$paymentMethodLabels[$paymentMethodKey]} — " . $paymentDate->format('d/m/Y') . "\n";
echo "Facture : " . ($skipInvoice ? 'non (--no-invoice)' : 'oui') . "\n\n";

if (!$apply) {
    echo "Dry run — rien n'a été écrit. Relancez avec --apply pour appliquer.\n";
    exit(0);
}

// ── Apply ────────────────────────────────────────────────────────────────

$pdo = $db->pdo();
$pdo->beginTransaction();
$orderId = null;
$invoice = null;

try {
    $order = $orders->create(
        'renewal',
        null,
        $bjUserId,
        (string) $bjUser['email'],
        $amount,
        $cartLines,
        $meta,
        paymentMethod: 'bank_transfer',
    );
    $orderId = (int) $order['id'];

    $orders->update($orderId, [
        'bank_transfer_confirmed_at' => $paymentDate->format('Y-m-d H:i:s'),
        'fulfilled_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ]);
    if (!$orders->transition($orderId, 'pending', 'fulfilled')) {
        throw new RuntimeException('Transition de statut échouée (exécution concurrente ?).');
    }

    $renewals->recordFormula($seasonStartYear, $bjUserId, $subscriptionType, $isCouple, $competitor, $lessons, $partnerBjUserId, $orderId);
    if ($isCouple) {
        $renewals->recordFormula($seasonStartYear, $partnerBjUserId, $subscriptionType, $isCouple, $partnerCompetitor, $lessons, $bjUserId, $orderId);
    }

    $audience = $pricing->subscription($subscriptionType, $season)['audience'];
    if ($audience !== 'jeune') {
        if ($lessons >= 1) {
            $orders->addLessonEnrollment($seasonStartYear, $bjUserId, (string) $bjUser['firstname'], (string) $bjUser['lastname'], (string) $bjUser['email'], $orderId);
        }
        if ($isCouple && $lessons >= 2) {
            $orders->addLessonEnrollment($seasonStartYear, $partnerBjUserId, (string) $partnerBjUser['firstname'], (string) $partnerBjUser['lastname'], (string) $partnerBjUser['email'], $orderId);
        }
    }

    $order = $orders->findById($orderId);

    if (!$skipInvoice) {
        $promoCodes = new PromoCodeRepository($db);
        $breakdown = new OrderBreakdownService($promoCodes);
        $composer = new InvoiceLineComposer($pricing, new InvoiceDescriptions($settings['paths']['pricing_data']));
        $bankDetails = new BankDetailsService(new SettingsRepository($db, $logger), $settings['club']['bank'] ?? []);
        $pdfService = new InvoicePdfService(
            $settings['paths']['uploads'],
            $settings['club'],
            dirname(__DIR__) . '/assets/logo.png',
            $bankDetails,
        );
        $invoiceService = new InvoiceService(
            new App\Repository\InvoiceRepository($db),
            new InvoiceNumberService($db),
            $breakdown,
            $composer,
            $pdfService,
            $settings['paths']['uploads'],
            $logger,
        );

        $context = [
            'subscription' => $pricing->subscription($subscriptionType, $season),
            'subscriptionKey' => $subscriptionType,
            'season' => $season,
            'residence' => $residence,
            'summerPack' => $summerPack,
            'people' => $people,
            'billingName' => trim($bjUser['firstname'] . ' ' . $bjUser['lastname']),
            'billingAddress' => [
                'address' => $bjUser['address'] ?? '',
                'postalcode' => $bjUser['postalcode'] ?? '',
                'city' => $bjUser['city'] ?? '',
            ],
        ];
        $invoice = $invoiceService->generateForOrder($order, $context);
        if ($invoice === null) {
            throw new RuntimeException('Génération de facture échouée — voir app_logs/membership.log.');
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fail('Échec — transaction annulée, rien n\'a été écrit : ' . $e->getMessage());
}

echo "OK — commande #{$orderId} créée et marquée fulfilled.\n";
echo 'member_formulas mis à jour pour ' . $bjUserId . ($isCouple ? " et {$partnerBjUserId}" : '') . ".\n";
if ($invoice !== null) {
    echo "Facture {$invoice['number']} générée : uploads/{$invoice['pdf_path']}\n";
}
