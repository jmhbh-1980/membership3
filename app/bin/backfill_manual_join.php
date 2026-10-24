<?php

declare(strict_types=1);

/**
 * Prefer the member page's « Enregistrer un paiement hors application »
 * (AdminManualOrderController), which records the same thing without SSH and
 * lets an admin change any amount. This script stays for what that page
 * doesn't do: a backdated invoice (--invoice-date), a number from a sequence
 * kept outside the app (--invoice-number), no invoice at all (--no-invoice).
 * Both go through App\Service\ManualOrderService.
 *
 * One-off reconciliation: backfills a member's local records — member_formulas,
 * lesson_enrollments, an `orders` row, and an invoice — for a season they
 * joined and paid for directly through the club (espèces/chèque/virement)
 * before this app existed, so they never went through the wizard here. Also
 * the way to record a member the club settled by hand since then because the
 * app can't take their payment (coupons sport).
 *
 * Balle Jaune already has this member as active for the season — that's not
 * in question, RenewalService::subscriptionCovers() reads BJ's own
 * subscription_date_end directly, so the renewal page already shows them as
 * paid, and this script never writes to BJ. What's missing is everything this
 * app itself owns and BJ has no field for: licence kind
 * (member_formulas.competitor), cours collectifs enrollment
 * (lesson_enrollments), and a proper invoice.
 *
 * The order is recorded as kind='renewal' rather than 'join' — this is
 * unrelated to which price grid they're actually charged from (see
 * --renewal-pricing below) — because every "which orders belong to this
 * member" lookup in this codebase (the member's own "Mes factures" page, the
 * admin dashboard's paid-date column, the invoice manual-recovery button)
 * matches a join order through application_id -> application_people, which
 * doesn't exist here. A renewal order is matched directly by bj_user_id
 * everywhere, which does exist. Nothing downstream (invoice wording, cart
 * line labels) is derived from `kind` itself — see
 * app/src/Service/InvoiceLineComposer.php.
 *
 * By default the cotisation is priced at the "1ère inscription" rate
 * (PricingService's premiere grid) — the honest default for someone who
 * never had a formula here before. Pass --renewal-pricing (with
 * --pricing-note explaining why) when the club/admin explicitly decided to
 * charge this member the renouvellement rate instead — e.g. they're actually
 * an old member from before any system existed, and the admin chose to
 * honor their tenure rather than charge them as a brand-new joiner. This
 * only changes which price grid is used; kind stays 'renewal' either way,
 * and cart_lines/the invoice will correctly say "(renouvellement)" too, since
 * that label comes from PricingService itself.
 *
 * Dry-run by default — prints exactly what would be written. Pass --apply to
 * commit (one DB transaction). Refuses to run twice for the same member+season
 * unless --force — which doesn't just bypass the guard, it properly supersedes
 * the prior record with ManualOrderService::delete(), the same withdrawal the
 * order page offers: the old order, its formula and lesson rows and its
 * invoice go (old PDF included), before the new ones are created. The old
 * invoice's number is reclaimed (last_number decremented) so nothing is left
 * permanently skipped; if a later invoice already exists in that same
 * counter, deleting would leave a gap, so the script refuses and stops — that
 * needs a human decision, not an automatic one. A manually-numbered invoice
 * (--invoice-number, sequence=0) is simply deleted, no counter involved. Only
 * a payment recorded outside the app can be superseded, never an online one.
 *
 * The computed price (via the same PricingService used everywhere else) must
 * match --amount exactly, as a check on the options passed. If the club
 * genuinely agreed a different price, record it from the member's page,
 * where each line's amount can be changed.
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
 *   --payment-method=KEY       required — especes | cheque | virement | carte | coupons-sport | autre
 *   --payment-note=TEXT        anything the audit trail should keep about how it was settled —
 *                                e.g. coupons worth more than the amount due, no change given.
 *                                --amount stays the amount due: that is what gets invoiced.
 *   --competitor                flag — drives fédérale vs pass (ignored for jeune)
 *   --licence-removed           flag — licence waived: no licence line is charged or invoiced
 *                                (the invoice reads "sans licence (retirée)"); needs
 *                                --licence-removal-reason
 *   --licence-removal-reason=TEXT  required with --licence-removed — why (audit trail)
 *   --couple                    flag — needs --partner-bj-user-id
 *   --partner-bj-user-id=INT   required if --couple
 *   --partner-competitor        flag — partner's licence kind
 *   --partner-licence-removed   flag — same waiver for the partner; needs --couple and
 *                                --partner-licence-removal-reason
 *   --partner-licence-removal-reason=TEXT  required with --partner-licence-removed
 *   --lessons=N                 default 0 — cours collectifs slots (0-2, couples only get 2)
 *   --pricing-residence=KEY     garennois | hors-commune — the grid to charge at when the club
 *                                granted this member a residence exception. Defaults to
 *                                --residence, which stays where they actually live. Also what
 *                                opens Midi to a non-resident (its only Garennois-only formula).
 *   --summer-pack                flag — Pack été (solo only, no lessons)
 *   --no-invoice                 skip invoice/PDF generation
 *   --renewal-pricing            flag — charge the renouvellement rate instead of 1ère inscription;
 *                                 needs --pricing-note
 *   --pricing-note=TEXT          required with --renewal-pricing — why the club granted it (audit trail)
 *   --invoice-date=YYYY-MM-DD    default: --payment-date — the invoice's issuance date. Determines
 *                                 both the printed "Date de facture" and which Aug1-Jul31 bookkeeping
 *                                 year/SQ- sequence it lands in (InvoiceNumberService) — so a payment
 *                                 from before 1 August belongs to the PRIOR invoicing year's sequence,
 *                                 not the current one. Pass an explicit value to opt out of the
 *                                 --payment-date default (e.g. --invoice-date=today for "now").
 *   --invoice-number=TEXT        default: auto (SQ-<year>-<n>, the app's own sequence). Set this
 *                                 when the club already numbered this bookkeeping year by hand
 *                                 (or with a separate system) before/outside this app — this app's
 *                                 own SQ- counter for that year is left completely untouched
 *                                 (never allocated), so a manual number here can never collide with
 *                                 or desync a sequence it was never part of. Must be globally unique.
 *   --apply                      write for real (default: dry run)
 *   --force                      bypass the idempotency guard only (not the price check)
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Repository\AuditLogRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Repository\PromoCodeRepository;
use App\Repository\ResidenceExceptionRepository;
use App\Repository\SettingsRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BankDetailsService;
use App\Service\InvoiceDescriptions;
use App\Service\InvoiceLineComposer;
use App\Service\InvoiceNumberService;
use App\Service\InvoicePdfService;
use App\Service\InvoiceService;
use App\Service\ManualOrderService;
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
$invoiceDateOpt = opt($argv, 'invoice-date');
$paymentMethodKey = opt($argv, 'payment-method') ?? '';
$paymentNote = trim(opt($argv, 'payment-note') ?? '');
$competitor = hasFlag($argv, 'competitor');
$licenceRemoved = hasFlag($argv, 'licence-removed');
$licenceRemovalReason = trim(opt($argv, 'licence-removal-reason') ?? '');
$isCouple = hasFlag($argv, 'couple');
$partnerBjUserId = (int) (opt($argv, 'partner-bj-user-id') ?? 0);
$partnerCompetitor = hasFlag($argv, 'partner-competitor');
$partnerLicenceRemoved = hasFlag($argv, 'partner-licence-removed');
$partnerLicenceRemovalReason = trim(opt($argv, 'partner-licence-removal-reason') ?? '');
$lessons = (int) (opt($argv, 'lessons') ?? 0);
// Empty = charge at the factual residence; set = the club granted this member
// the other grid (see migration 0022). Replaces the old --midi-residency-override
// flag, which only ever meant "price this hors-commune person as Garennois".
$pricingResidenceOpt = opt($argv, 'pricing-residence') ?? '';
$summerPack = hasFlag($argv, 'summer-pack');
$skipInvoice = hasFlag($argv, 'no-invoice');
$renewalPricing = hasFlag($argv, 'renewal-pricing');
$pricingNote = opt($argv, 'pricing-note') ?? '';
$manualInvoiceNumber = opt($argv, 'invoice-number');
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
if ($pricingResidenceOpt !== ''
    && !in_array($pricingResidenceOpt, [PricingService::RESIDENCE_GARENNOIS, PricingService::RESIDENCE_HORS_COMMUNE], true)) {
    fail('--pricing-residence invalide (garennois|hors-commune).');
}
$pricingResidence = PricingService::pricingResidence($residence, $pricingResidenceOpt);
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
if ($invoiceDateOpt === null || $invoiceDateOpt === '') {
    $invoiceDate = $paymentDate; // sensible default: the invoice belongs to when the money actually moved
} elseif ($invoiceDateOpt === 'today') {
    $invoiceDate = new DateTimeImmutable();
} else {
    $invoiceDate = DateTimeImmutable::createFromFormat('Y-m-d', $invoiceDateOpt);
    if ($invoiceDate === false) {
        fail('--invoice-date invalide (attendu YYYY-MM-DD, ou "today").');
    }
}
if (!isset(ManualOrderService::PAYMENT_METHODS[$paymentMethodKey])) {
    fail('--payment-method invalide (' . implode('|', array_keys(ManualOrderService::PAYMENT_METHODS)) . ').');
}
if ($isCouple && $partnerBjUserId <= 0) {
    fail('--couple nécessite --partner-bj-user-id.');
}
if ($licenceRemoved && $licenceRemovalReason === '') {
    fail('--licence-removed nécessite --licence-removal-reason="..." expliquant pourquoi la licence n\'est pas prise.');
}
if ($partnerLicenceRemoved && !$isCouple) {
    fail('--partner-licence-removed nécessite --couple.');
}
if ($partnerLicenceRemoved && $partnerLicenceRemovalReason === '') {
    fail('--partner-licence-removed nécessite --partner-licence-removal-reason="..." expliquant pourquoi la licence n\'est pas prise.');
}
if ($renewalPricing && trim($pricingNote) === '') {
    fail('--renewal-pricing nécessite --pricing-note="..." expliquant pourquoi le club a accordé ce tarif.');
}
if ($manualInvoiceNumber !== null) {
    $manualInvoiceNumber = trim($manualInvoiceNumber);
    if ($manualInvoiceNumber === '') {
        fail('--invoice-number ne peut pas être vide.');
    }
    if ($skipInvoice) {
        fail('--invoice-number et --no-invoice sont incompatibles.');
    }
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
$invoices = new InvoiceRepository($db);
$manualOrders = new ManualOrderService(
    $db,
    $pricing,
    $orders,
    new RenewalService($db, $pricing),
    new ResidenceExceptionRepository($db),
    $invoices,
    new InvoiceService(
        $invoices,
        new InvoiceNumberService($db),
        new OrderBreakdownService(new PromoCodeRepository($db)),
        new InvoiceLineComposer($pricing, new InvoiceDescriptions($settings['paths']['pricing_data'])),
        new InvoicePdfService(
            $settings['paths']['uploads'],
            $settings['club'],
            dirname(__DIR__) . '/assets/logo.png',
            new BankDetailsService(new SettingsRepository($db, $logger), $settings['club']['bank'] ?? []),
        ),
        $settings['paths']['uploads'],
        $logger,
    ),
    new AuditLogRepository($db),
    $settings['paths']['uploads'],
);

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

$input = [
    'purchase'                    => ManualOrderService::MEMBERSHIP,
    'season'                      => $season,
    'effectiveDate'               => $joinDate,
    'subscriptionType'            => $subscriptionType,
    'premiere'                    => !$renewalPricing,
    'competitor'                  => $competitor,
    'licenceRemoved'              => $licenceRemoved,
    'licenceRemovalReason'        => $licenceRemovalReason,
    'lessons'                     => $lessons,
    'summerPack'                  => $summerPack,
    'isCouple'                    => $isCouple,
    'partnerCompetitor'           => $partnerCompetitor,
    'partnerLicenceRemoved'       => $partnerLicenceRemoved,
    'partnerLicenceRemovalReason' => $partnerLicenceRemovalReason,
    // Named here rather than read from BJ's postcode and the season's grants.
    'residence'                   => $residence,
    'pricingResidence'            => $pricingResidence,
];

// ── Idempotency guard / supersede plan ────────────────────────────────────

$refusal = $manualOrders->refusal($bjUserId, $input, $isCouple ? $partnerBjUserId : 0);
$supersedeOrder = null;
$supersedeInvoice = null;
if ($refusal !== null) {
    if (!$force) {
        fail($refusal['message'] . ($refusal['orderId'] !== null ? " (commande #{$refusal['orderId']})" : '') . ' Relancez avec --force pour la remplacer.');
    }
    $supersedeOrder = $refusal['orderId'] !== null ? $orders->findById($refusal['orderId']) : null;
    if ($supersedeOrder === null || !$manualOrders->deletable($supersedeOrder)) {
        fail(
            'La fiche existante ne peut pas être remplacée : seule une commande enregistrée hors application, dont la facture '
            . 'est encore la dernière émise de son exercice, peut l\'être (sinon la supprimer laisserait un trou dans la numérotation, '
            . 'ou effacerait un paiement en ligne). Contactez-moi pour décider de la marche à suivre.'
        );
    }
    $supersedeInvoice = $invoices->findByOrderId((int) $supersedeOrder['id']);
}

if ($manualInvoiceNumber !== null) {
    $numberCheck = $db->pdo()->prepare('SELECT 1 FROM invoices WHERE number = ? AND order_id != ?');
    $numberCheck->execute([$manualInvoiceNumber, (int) ($supersedeOrder['id'] ?? 0)]);
    if ($numberCheck->fetchColumn() !== false) {
        fail("Le numéro de facture « {$manualInvoiceNumber} » est déjà utilisé par une autre facture.");
    }
}

if ($supersedeOrder !== null) {
    echo "⚠ Remplace la commande #{$supersedeOrder['id']} existante (" . number_format((float) $supersedeOrder['amount'], 2, ',', ' ') . " €, {$supersedeOrder['created_at']}).\n";
    if ($supersedeInvoice !== null) {
        $reclaim = (int) $supersedeInvoice['sequence'] > 0 ? ' — numéro réutilisable, sera réattribué' : ' — numéro manuel, sans compteur à ajuster';
        echo "  Sa facture {$supersedeInvoice['number']} sera supprimée{$reclaim}.\n";
    }
    echo "\n";
}

// ── Price the membership (same engine as a real join) ─────────────────────

try {
    $lines = $manualOrders->price($bjUser, $input)['lines'];
} catch (InvalidArgumentException $e) {
    fail('Erreur de tarification : ' . $e->getMessage());
}

$computedTotal = round(array_sum(array_column($lines, 'amount')), 2);
echo "Panier calculé :\n";
foreach ($lines as $line) {
    printf("  %-10s %-55s %8.2f €\n", $line['type'], $line['label'], $line['amount']);
}
printf("  %-66s %8.2f €\n", 'TOTAL', $computedTotal);
echo "\n";

if (abs($computedTotal - $amount) > 0.01) {
    fail(sprintf(
        "Le montant calculé (%.2f €) ne correspond pas au montant réellement encaissé (%.2f €). ".
        "Vérifiez les options passées (formule, résidence, date d'adhésion pour le prorata, cours collectifs…). ".
        "Si le club a réellement accordé un tarif différent, enregistrez ce paiement depuis la fiche du membre ".
        "(« Enregistrer un paiement hors application »), qui permet de modifier chaque montant.",
        $computedTotal,
        $amount,
    ));
}

$invoicingYear = (new InvoiceNumberService($db))->seasonLabelFor($invoiceDate);

echo 'Saison : ' . $season->label() . ($summerPack ? ' (Pack été)' : '') . "\n";
echo "Formule : {$subscriptionType} / {$residence}"
    . ($pricingResidence !== $residence ? " (facturé au tarif {$pricingResidence} — exception)" : '')
    . ($competitor ? ' / compétiteur' : '') . "\n";
if ($licenceRemoved) {
    echo "Licence retirée — motif : {$licenceRemovalReason}\n";
}
if ($partnerLicenceRemoved) {
    echo "Licence du partenaire retirée — motif : {$partnerLicenceRemovalReason}\n";
}
echo 'Tarif : ' . ($renewalPricing ? "renouvellement — accordé par le club ({$pricingNote})" : '1ère inscription') . "\n";
echo "Cours collectifs : {$lessons}\n";
echo 'Paiement réel : ' . number_format($amount, 2, ',', ' ') . ' € — ' . ManualOrderService::PAYMENT_METHODS[$paymentMethodKey] . ' — ' . $paymentDate->format('d/m/Y')
    . ($paymentNote !== '' ? " ({$paymentNote})" : '') . "\n";
$invoiceNumberDesc = $manualInvoiceNumber !== null
    ? "numéro manuel « {$manualInvoiceNumber} »"
    : "compteur automatique SQ-{$invoicingYear}-…";
echo "Facture : " . ($skipInvoice ? 'non (--no-invoice)' : "oui — datée du {$invoiceDate->format('d/m/Y')}, exercice comptable {$invoicingYear}, {$invoiceNumberDesc}") . "\n\n";

if (!$apply) {
    echo "Dry run — rien n'a été écrit. Relancez avec --apply pour appliquer.\n";
    exit(0);
}

// ── Apply ────────────────────────────────────────────────────────────────

$recordedBy = get_current_user() ?: 'cli';
if ($supersedeOrder !== null) {
    // Its own transaction, before the new record's: if creating then fails,
    // rerun without --force — the guard above has nothing left to refuse.
    $manualOrders->delete($supersedeOrder, $recordedBy);
}

try {
    $result = $manualOrders->create(
        $bjUser,
        $partnerBjUser,
        $input,
        [],
        ['date' => $paymentDate, 'method' => $paymentMethodKey, 'note' => $paymentNote],
        $recordedBy,
        [
            'issuedAt'            => $invoiceDate,
            'manualInvoiceNumber' => $manualInvoiceNumber,
            'withInvoice'         => !$skipInvoice,
            'extraMeta'           => [
                'manualReconciliationReason' => "Adhésion conclue et réglée directement auprès du club, en dehors de l'application (saison {$season->label()}).",
                'renewalPricingNote'         => $renewalPricing ? $pricingNote : '',
                'invoiceDate'                => $invoiceDate->format('Y-m-d'),
                'manualInvoiceNumber'        => $manualInvoiceNumber ?? '',
                'backfilledBy'               => $recordedBy,
                'backfilledAt'               => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        ],
    );
} catch (Throwable $e) {
    fail('Échec — transaction annulée, rien n\'a été écrit : ' . $e->getMessage());
}

echo "OK — commande #{$result['order']['id']} créée et marquée fulfilled.\n";
echo 'member_formulas mis à jour pour ' . $bjUserId . ($isCouple ? " et {$partnerBjUserId}" : '') . ".\n";
if ($result['invoice'] !== null) {
    echo "Facture {$result['invoice']['number']} générée : uploads/{$result['invoice']['pdf_path']}\n";
}
