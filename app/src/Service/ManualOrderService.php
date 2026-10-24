<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AuditLogRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Repository\ResidenceExceptionRepository;
use App\Support\Db;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Records a payment the club took outside the app — cash, cheque, transfer,
 * card on another terminal, coupons sport — as a paid order with its numbered
 * invoice, so the member's records and "Mes factures" match what they paid.
 * Used by the admin page (/admin/membres/{id}/paiement-hors-appli) and by
 * bin/backfill_manual_join.php.
 *
 * Invoice only: Balle Jaune is never written. The admin renews the member (or
 * adds their credits) there by hand, as before.
 *
 * Three purchases: a membership (cotisation, licence or its waiver, cours
 * collectifs, solo or couple — kind 'renewal', since every "orders of this
 * member" lookup matches a renewal by bj_user_id, whereas a join goes through
 * an application this has none of), cours collectifs alone ('lessons') and
 * ticket credits ('credits'). Each order carries the same meta keys an online
 * order of its kind does, so the order page, the invoice composer, the
 * licences board and "Générer la facture" read it unchanged.
 *
 * Prices start from the grid and an admin may change any line: the order
 * amount is always the sum of its lines, and the grid lines are kept in meta.
 */
final class ManualOrderService
{
    public const string MEMBERSHIP = 'adhesion';
    public const string LESSONS = 'cours';
    public const string CREDITS = 'credits';

    public const array PURCHASES = [
        self::MEMBERSHIP => 'Adhésion',
        self::LESSONS    => 'Cours collectifs seuls',
        self::CREDITS    => 'Crédits (Formule Tickets)',
    ];

    public const array PAYMENT_METHODS = [
        'especes'       => 'Espèces',
        'cheque'        => 'Chèque',
        'virement'      => 'Virement bancaire',
        'carte'         => 'Carte bancaire',
        'coupons-sport' => 'Coupons Sport',
        'autre'         => 'Autre',
    ];

    private const float MAX_LINE_AMOUNT = 9999.99;
    private const int MAX_PACKS = 20;

    public function __construct(
        private readonly Db $db,
        private readonly PricingService $pricing,
        private readonly OrderRepository $orders,
        private readonly RenewalService $renewals,
        private readonly ResidenceExceptionRepository $residenceExceptions,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceService $invoiceService,
        private readonly AuditLogRepository $auditLog,
        private readonly string $uploadsDir,
    ) {
    }

    /**
     * The grid price of a purchase, line by line, before any admin change.
     *
     * $input keys — purchase, season (Season), effectiveDate (drives the
     * prorata); for a membership: subscriptionType, premiere, competitor,
     * licenceRemoved, lessons, summerPack, isCouple, partnerCompetitor,
     * partnerLicenceRemoved, and optionally residence / pricingResidence
     * (otherwise the postcode and the season's active grant decide); for
     * credits: packs.
     *
     * @return array{lines: list<array>, residence: string, pricingResidence: string}
     * @throws InvalidArgumentException with a message an admin can act on
     */
    public function price(array $bjUser, array $input): array
    {
        ['residence' => $residence, 'pricingResidence' => $pricingResidence] = $this->residences(
            $bjUser, $input['season'], (string) ($input['pricingResidence'] ?? ''), (string) ($input['residence'] ?? ''));

        $lines = match ($input['purchase']) {
            self::MEMBERSHIP => $this->membershipLines($input, $pricingResidence),
            self::LESSONS    => [$this->lessonLine($input)],
            self::CREDITS    => [$this->creditsLine($input)],
            default          => throw new InvalidArgumentException('Achat inconnu.'),
        };
        return ['lines' => $lines, 'residence' => $residence, 'pricingResidence' => $pricingResidence];
    }

    /**
     * Where the member lives (from their Balle Jaune postcode) and the grid
     * they are charged at: the season's active grant if any. The CLI names
     * both itself (--residence, --pricing-residence) and then wins.
     *
     * @return array{residence: string, pricingResidence: string}
     */
    public function residences(array $bjUser, Season $season, string $override = '', string $residence = ''): array
    {
        $residence = $residence !== '' ? $residence : $this->pricing->residenceForZip((string) ($bjUser['postalcode'] ?? ''));
        $override = $override !== '' ? $override : $this->residenceExceptions->overrideFor($season->startYear, (int) $bjUser['user_id']);
        return ['residence' => $residence, 'pricingResidence' => PricingService::pricingResidence($residence, $override)];
    }

    /**
     * Why this purchase can't be recorded for this member, if it can't: the
     * season's membership is already on an order, or they are already enrolled
     * in the lessons. The order is given so the admin can open it.
     *
     * @return ?array{message: string, orderId: ?int}
     */
    public function refusal(int $bjUserId, array $input, int $partnerBjUserId = 0): ?array
    {
        $season = $input['season'];
        if ($input['purchase'] === self::MEMBERSHIP) {
            foreach (array_filter([$bjUserId, $partnerBjUserId]) as $id) {
                $existing = $this->formulaRow($season->startYear, $id);
                if ($existing !== null) {
                    return [
                        'message' => ($id === $bjUserId ? 'Cet adhérent' : 'Son/sa conjoint(e)') . ' a déjà une adhésion enregistrée pour la saison '
                            . $season->label() . '.',
                        'orderId' => $existing['order_id'] !== null ? (int) $existing['order_id'] : null,
                    ];
                }
            }
        }
        if ($input['purchase'] === self::LESSONS && $this->orders->isEnrolledInLessons($bjUserId, $season->startYear)) {
            $stmt = $this->db->pdo()->prepare('SELECT order_id FROM lesson_enrollments WHERE bj_user_id = ? AND season_start_year = ? LIMIT 1');
            $stmt->execute([$bjUserId, $season->startYear]);
            $orderId = $stmt->fetchColumn();
            return [
                'message' => 'Cet adhérent est déjà inscrit aux cours collectifs pour la saison ' . $season->label() . '.',
                'orderId' => $orderId !== false && $orderId !== null ? (int) $orderId : null,
            ];
        }
        return null;
    }

    /**
     * The grid lines with the admin's amounts applied. A blank amount keeps the
     * grid price; a line raised above its full price takes the new amount as
     * its base, so the invoice never prints a negative reduction.
     *
     * @param array<int, string|float|null> $amounts by line index, as typed ("12,50" accepted)
     * @throws InvalidArgumentException
     */
    public static function applyAmounts(array $gridLines, array $amounts): array
    {
        $lines = [];
        foreach (array_values($gridLines) as $i => $line) {
            $typed = $amounts[$i] ?? null;
            if ($typed !== null && trim((string) $typed) !== '') {
                $value = str_replace([' ', ' ', ','], ['', '', '.'], trim((string) $typed));
                if (!is_numeric($value) || (float) $value < 0 || (float) $value > self::MAX_LINE_AMOUNT) {
                    throw new InvalidArgumentException('Montant invalide pour « ' . $line['label'] . ' » : '
                        . 'saisissez un nombre entre 0 et ' . number_format(self::MAX_LINE_AMOUNT, 2, ',', ' ') . ' €.');
                }
                $line['amount'] = round((float) $value, 2);
                $line['baseAmount'] = max((float) ($line['baseAmount'] ?? $line['amount']), $line['amount']);
            }
            $lines[] = $line;
        }
        if (round(array_sum(array_column($lines, 'amount')), 2) <= 0.0) {
            throw new InvalidArgumentException('Le total doit être supérieur à 0 €.');
        }
        return $lines;
    }

    /**
     * Records the order, everything it entitles the member to in this app,
     * and its invoice — all or nothing.
     *
     * $payment: date (DateTimeImmutable), method (PAYMENT_METHODS key), note.
     * $options (the CLI's extras): issuedAt, manualInvoiceNumber, withInvoice
     * (default true), extraMeta.
     *
     * @return array{order: array, invoice: ?array}
     * @throws InvalidArgumentException|RuntimeException
     */
    public function create(
        array $bjUser,
        ?array $partnerUser,
        array $input,
        array $amounts,
        array $payment,
        string $recordedBy,
        array $options = [],
    ): array {
        if (!isset(self::PAYMENT_METHODS[$payment['method'] ?? ''])) {
            throw new InvalidArgumentException('Choisissez le mode de paiement.');
        }
        $bjUserId = (int) $bjUser['user_id'];
        $partnerId = $partnerUser !== null ? (int) $partnerUser['user_id'] : 0;
        $isCouple = $input['purchase'] === self::MEMBERSHIP && !empty($input['isCouple']);
        if ($isCouple && $partnerId <= 0) {
            throw new InvalidArgumentException('Une adhésion en couple demande le/la conjoint(e).');
        }
        $refusal = $this->refusal($bjUserId, $input, $isCouple ? $partnerId : 0);
        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal['message']);
        }

        $priced = $this->price($bjUser, $input);
        $lines = self::applyAmounts($priced['lines'], $amounts);
        $total = round(array_sum(array_column($lines, 'amount')), 2);
        $season = $input['season'];
        $kind = match ($input['purchase']) {
            self::MEMBERSHIP => 'renewal',
            self::LESSONS    => 'lessons',
            self::CREDITS    => 'credits',
        };

        $meta = [
            'manualReconciliation' => true,
            'actualPaymentDate'    => $payment['date']->format('Y-m-d'),
            'actualPaymentMethod'  => self::PAYMENT_METHODS[$payment['method']],
            'actualPaymentNote'    => trim((string) ($payment['note'] ?? '')),
            'actualJoinDate'       => $input['effectiveDate']->format('Y-m-d'),
            'recordedBy'           => $recordedBy,
            'recordedAt'           => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'gridLines'            => $priced['lines'],
            'amountsEdited'        => $lines !== $priced['lines'],
            'seasonStartYear'      => $season->startYear,
        ] + $this->kindMeta($input, $bjUser, $partnerId, $priced) + ($options['extraMeta'] ?? []);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $order = $this->orders->create(
                $kind,
                null,
                $bjUserId,
                (string) ($bjUser['email'] ?? ''),
                $total,
                $lines,
                $meta,
                paymentMethod: 'manual',
                residence: $priced['residence'],
                pricingResidence: $priced['pricingResidence'],
            );
            $orderId = (int) $order['id'];
            $this->orders->update($orderId, ['fulfilled_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s')]);
            if (!$this->orders->transition($orderId, 'pending', 'fulfilled')) {
                throw new RuntimeException('Transition de statut échouée.');
            }

            match ($input['purchase']) {
                self::MEMBERSHIP => $this->recordMembership($input, $bjUser, $partnerUser, $orderId, $priced),
                self::LESSONS    => $this->recordLessons($season, $bjUser, $orderId),
                self::CREDITS    => null, // credits live in Balle Jaune only, added there by hand
            };

            $order = $this->orders->findById($orderId);
            $invoice = null;
            if ($options['withInvoice'] ?? true) {
                $invoice = $this->invoiceService->generateForOrder(
                    $order,
                    $this->invoiceContext($input, $bjUser, $order, $priced),
                    $options['issuedAt'] ?? null,
                    $options['manualInvoiceNumber'] ?? null,
                );
                if ($invoice === null) {
                    throw new RuntimeException('La facture n\'a pas pu être générée (voir app_logs/membership.log).');
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->auditLog->log($recordedBy, 'order.manual_create', 'order', (string) $orderId, [
            'kind' => $kind, 'bj_user_id' => $bjUserId, 'amount' => $total, 'invoice' => $invoice['number'] ?? null,
        ]);
        return ['order' => $order, 'invoice' => $invoice];
    }

    /**
     * Whether an order recorded here can be withdrawn: only while its invoice
     * is still the latest number issued (or was numbered by hand), since
     * deleting an earlier one would leave a gap in the numbering.
     */
    public function deletable(array $order): bool
    {
        if (($order['payment_method'] ?? '') !== 'manual') {
            return false;
        }
        $invoice = $this->invoices->findByOrderId((int) $order['id']);
        if ($invoice === null || (int) $invoice['sequence'] === 0) {
            return true;
        }
        $stmt = $this->db->pdo()->prepare('SELECT last_number FROM invoice_counters WHERE season_label = ?');
        $stmt->execute([$invoice['season_label']]);
        return (int) $stmt->fetchColumn() === (int) $invoice['sequence'];
    }

    /**
     * Withdraws an order recorded here, with what it created: its invoice (the
     * number is given back to the counter), formula rows, lesson enrolments,
     * and — for lessons alone — the formula's lessons count as it was before.
     * A residence exception extended to a partner stays: it was granted.
     *
     * @throws RuntimeException when the order isn't deletable
     */
    public function delete(array $order, string $deletedBy): void
    {
        if (!$this->deletable($order)) {
            throw new RuntimeException('Cette commande ne peut plus être supprimée : sa facture n\'est plus la dernière émise.');
        }
        $orderId = (int) $order['id'];
        $meta = json_decode((string) ($order['meta'] ?? '{}'), true) ?: [];
        $invoice = $this->invoices->findByOrderId($orderId);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            if ($invoice !== null) {
                $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$invoice['id']]);
                if ((int) $invoice['sequence'] > 0) {
                    $pdo->prepare('UPDATE invoice_counters SET last_number = last_number - 1 WHERE season_label = ? AND last_number = ?')
                        ->execute([$invoice['season_label'], $invoice['sequence']]);
                }
            }
            $pdo->prepare('DELETE FROM lesson_enrollments WHERE order_id = ?')->execute([$orderId]);
            $pdo->prepare('DELETE FROM member_formulas WHERE order_id = ?')->execute([$orderId]);
            if ($order['kind'] === 'lessons' && array_key_exists('previousFormulaLessons', $meta) && $meta['previousFormulaLessons'] !== null) {
                $pdo->prepare('UPDATE member_formulas SET lessons = ? WHERE season_start_year = ? AND bj_user_id = ?')
                    ->execute([(int) $meta['previousFormulaLessons'], (int) $meta['seasonStartYear'], (int) $order['bj_user_id']]);
            }
            $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($invoice !== null && is_file($this->uploadsDir . '/' . $invoice['pdf_path'])) {
            unlink($this->uploadsDir . '/' . $invoice['pdf_path']);
        }
        $this->auditLog->log($deletedBy, 'order.manual_delete', 'order', (string) $orderId, [
            'kind' => $order['kind'], 'bj_user_id' => (int) $order['bj_user_id'], 'amount' => (float) $order['amount'],
            'invoice' => $invoice['number'] ?? null,
        ]);
    }

    // ── Pricing ──────────────────────────────────────────────────────────

    private function membershipLines(array $input, string $pricingResidence): array
    {
        $quote = $this->pricing->quote(
            (string) ($input['subscriptionType'] ?? ''),
            $pricingResidence,
            premiere: (bool) ($input['premiere'] ?? true),
            season: $input['season'],
            joinDate: $input['effectiveDate'],
            isCouple: !empty($input['isCouple']),
            people: self::people($input),
            lessonsCount: (int) ($input['lessons'] ?? 0),
            summerPack: !empty($input['summerPack']),
            studentDiscount: false,
            promo: null,
        );
        return array_map(static fn ($l) => [
            'type' => $l->type, 'label' => $l->label, 'amount' => $l->amount,
            'baseAmount' => $l->baseAmount, 'personIndex' => $l->personIndex,
        ], $quote->lines);
    }

    private function lessonLine(array $input): array
    {
        $addOn = $this->pricing->lessonAddOn($input['season'], $input['effectiveDate']);
        return ['type' => 'lessons', 'label' => $addOn['label'], 'amount' => $addOn['amount'], 'baseAmount' => $addOn['baseAmount'], 'personIndex' => null];
    }

    private function creditsLine(array $input): array
    {
        $pack = $this->pricing->ticketPack($input['season']);
        if ($pack['price'] === null) {
            throw new InvalidArgumentException('La Formule Tickets n\'a pas de prix pour la saison ' . $input['season']->label() . '.');
        }
        $packs = (int) ($input['packs'] ?? 1);
        if ($packs < 1 || $packs > self::MAX_PACKS) {
            throw new InvalidArgumentException('Nombre de packs invalide (1 à ' . self::MAX_PACKS . ').');
        }
        $amount = round((float) $pack['price'] * $packs, 2);
        return [
            'type' => 'tickets', 'label' => $pack['label'] . ($packs > 1 ? ' × ' . $packs : ''),
            'amount' => $amount, 'baseAmount' => $amount, 'personIndex' => null,
        ];
    }

    private static function people(array $input): array
    {
        $self = ['competitor' => !empty($input['competitor']), 'licenceRemoved' => !empty($input['licenceRemoved'])];
        return !empty($input['isCouple'])
            ? [$self, ['competitor' => !empty($input['partnerCompetitor']), 'licenceRemoved' => !empty($input['partnerLicenceRemoved'])]]
            : [$self];
    }

    // ── Recording ────────────────────────────────────────────────────────

    /** The meta an online order of the same kind carries. */
    private function kindMeta(array $input, array $bjUser, int $partnerId, array $priced): array
    {
        $formula = $input['purchase'] === self::LESSONS ? $this->formulaRow($input['season']->startYear, (int) $bjUser['user_id']) : null;
        return match ($input['purchase']) {
            self::MEMBERSHIP => [
                'subscriptionType'            => (string) $input['subscriptionType'],
                'isCouple'                    => !empty($input['isCouple']),
                'residence'                   => $priced['residence'],
                'pricingResidence'            => $priced['pricingResidence'],
                'competitor'                  => !empty($input['competitor']),
                'partnerCompetitor'           => !empty($input['partnerCompetitor']),
                'partnerBjUserId'             => !empty($input['isCouple']) ? $partnerId : 0,
                'lessons'                     => (int) ($input['lessons'] ?? 0),
                'lateSettlement'              => !empty($input['summerPack']),
                'licenceRemoved'              => !empty($input['licenceRemoved']),
                'licenceRemovalReason'        => trim((string) ($input['licenceRemovalReason'] ?? '')),
                'partnerLicenceRemoved'       => !empty($input['partnerLicenceRemoved']),
                'partnerLicenceRemovalReason' => trim((string) ($input['partnerLicenceRemovalReason'] ?? '')),
                'renewalPricingGranted'       => empty($input['premiere']),
            ],
            self::LESSONS => [
                'firstname'              => (string) ($bjUser['firstname'] ?? ''),
                'lastname'               => (string) ($bjUser['lastname'] ?? ''),
                'email'                  => (string) ($bjUser['email'] ?? ''),
                // Restored if the order is deleted: markLessonsTaken() below raises it.
                'previousFormulaLessons' => $formula !== null ? (int) $formula['lessons'] : null,
            ],
            self::CREDITS => [
                'tickets' => (int) $this->pricing->ticketPack($input['season'])['tickets'] * (int) ($input['packs'] ?? 1),
                'packs'   => (int) ($input['packs'] ?? 1),
            ],
        };
    }

    private function recordMembership(array $input, array $bjUser, ?array $partnerUser, int $orderId, array $priced): void
    {
        $season = $input['season'];
        $isCouple = !empty($input['isCouple']);
        $bjUserId = (int) $bjUser['user_id'];
        $partnerId = $isCouple ? (int) $partnerUser['user_id'] : 0;
        $lessons = (int) ($input['lessons'] ?? 0);

        $this->renewals->recordFormula($season->startYear, $bjUserId, (string) $input['subscriptionType'], $isCouple,
            !empty($input['competitor']), $lessons, $partnerId, $orderId, $priced['pricingResidence']);
        if ($isCouple) {
            $this->renewals->recordFormula($season->startYear, $partnerId, (string) $input['subscriptionType'], $isCouple,
                !empty($input['partnerCompetitor']), $lessons, $bjUserId, $orderId, $priced['pricingResidence']);
        }

        if ($this->pricing->subscription((string) $input['subscriptionType'], $season)['audience'] !== 'jeune') {
            $enrolled = $lessons >= 1 ? [$bjUser] : [];
            if ($isCouple && $lessons >= 2) {
                $enrolled[] = $partnerUser;
            }
            foreach ($enrolled as $person) {
                $this->orders->addLessonEnrollment($season->startYear, (int) $person['user_id'], (string) ($person['firstname'] ?? ''),
                    (string) ($person['lastname'] ?? ''), (string) ($person['email'] ?? ''), $orderId);
            }
        }

        // A couple is priced for both at the payer's grant (club rule): the
        // partner gets the same grant for the season, as at fulfillment.
        if ($isCouple && $priced['pricingResidence'] !== $priced['residence']) {
            $this->residenceExceptions->extendTo($season->startYear, $bjUserId, $partnerId);
        }
    }

    private function recordLessons(Season $season, array $bjUser, int $orderId): void
    {
        $this->orders->addLessonEnrollment($season->startYear, (int) $bjUser['user_id'], (string) ($bjUser['firstname'] ?? ''),
            (string) ($bjUser['lastname'] ?? ''), (string) ($bjUser['email'] ?? ''), $orderId);
        $this->renewals->markLessonsTaken($season->startYear, (int) $bjUser['user_id']);
    }

    private function invoiceContext(array $input, array $bjUser, array $order, array $priced): array
    {
        if ($input['purchase'] !== self::MEMBERSHIP) {
            return InvoiceService::addOnContext($order, $bjUser);
        }
        $season = $input['season'];
        return [
            'subscription'     => $this->pricing->subscription((string) $input['subscriptionType'], $season),
            'subscriptionKey'  => (string) $input['subscriptionType'],
            'season'           => $season,
            'residence'        => $priced['residence'],
            'pricingResidence' => $priced['pricingResidence'],
            'summerPack'       => !empty($input['summerPack']),
            'people'           => self::people($input),
        ] + array_intersect_key(InvoiceService::addOnContext($order, $bjUser), ['billingName' => true, 'billingAddress' => true]);
    }

    private function formulaRow(int $seasonStartYear, int $bjUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT order_id, lessons FROM member_formulas WHERE season_start_year = ? AND bj_user_id = ?');
        $stmt->execute([$seasonStartYear, $bjUserId]);
        return $stmt->fetch() ?: null;
    }
}
