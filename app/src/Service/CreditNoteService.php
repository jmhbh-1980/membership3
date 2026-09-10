<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ApplicationRepository;
use App\Repository\CreditNoteRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Repository\PromoCodeRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Support\Logger;
use DateTimeImmutable;

/**
 * Issues an avoir (credit note) when a residence exception is granted *after*
 * the member has already paid and been sent a full-price numbered invoice.
 *
 * The normal path is the other way round — an admin grants the exception before
 * the member renews, so the price is right first time and no avoir exists. This
 * is the remedy for the late decision, and it is deliberately a real numbered
 * accounting document rather than an off-books gesture.
 *
 * The credited amount is never "the Garennois price minus the hors-commune
 * price": prorata scales the cotisation, and a promo or student discount is a
 * percentage of the cotisation+lessons subtotal, so both move with the grid.
 * The whole cart is re-quoted at the granted grid and the totals differenced.
 *
 * Because that re-quote is a reconstruction, it is checked before it is
 * trusted: re-quoting at the grid the order was *actually* charged at must
 * reproduce the order's frozen total to the cent. If it does not — the season's
 * prices were edited in /admin/tarifs after the payment, or the order was
 * hand-made — this refuses rather than issuing an avoir for a wrong amount.
 */
final class CreditNoteService
{
    public function __construct(
        private readonly CreditNoteRepository $creditNotes,
        private readonly InvoiceRepository $invoices,
        private readonly OrderRepository $orders,
        private readonly ApplicationRepository $applications,
        private readonly PromoCodeRepository $promoCodes,
        private readonly InvoiceNumberService $numbers,
        private readonly PricingService $pricing,
        private readonly InvoicePdfService $pdf,
        private readonly BalleJauneClient $bj,
        private readonly Logger $logger,
        private readonly string $uploadsDir,
    ) {
    }

    /**
     * What an avoir on this order would credit, and whether it can be issued.
     * Pure assessment — writes nothing, so the admin screens can call it freely
     * to show "84,00 € à rembourser" next to an exception.
     *
     * @return array{eligible:bool, amount:float, problem:?string, existing:?array}
     */
    public function assess(array $order, string $grantedPricingResidence): array
    {
        $existing = $this->creditNotes->findByOrderId((int) $order['id']);
        if ($existing !== null) {
            return ['eligible' => false, 'amount' => (float) $existing['amount'], 'problem' => null, 'existing' => $existing];
        }

        $no = fn (string $problem): array => ['eligible' => false, 'amount' => 0.0, 'problem' => $problem, 'existing' => null];

        if (!in_array((string) $order['status'], ['fulfilled', 'processed'], true)) {
            return $no('La commande n\'est pas finalisée : le tarif accordé s\'appliquera directement, sans avoir.');
        }
        if ((int) ($order['installment_number'] ?? 0) > 0) {
            return $no('Paiement échelonné : la régularisation doit être faite à la main.');
        }
        if ($this->invoices->findByOrderId((int) $order['id']) === null) {
            return $no('Aucune facture n\'a été émise pour cette commande.');
        }

        $inputs = $this->quoteInputs($order);
        if ($inputs === null) {
            return $no('Impossible de reconstituer le panier de cette commande.');
        }

        $charged = (string) $order['pricing_residence'] !== ''
            ? (string) $order['pricing_residence']
            : (string) $order['residence'];
        if ($charged === '' || $charged === $grantedPricingResidence) {
            return $no('Cette commande a déjà été facturée au tarif accordé.');
        }

        try {
            $check = $this->quoteAt($inputs, $charged);
            $granted = $this->quoteAt($inputs, $grantedPricingResidence);
        } catch (\Throwable $e) {
            return $no('Recalcul impossible : ' . $e->getMessage());
        }

        if (round($check->total(), 2) !== round((float) $order['amount'], 2)) {
            return $no(sprintf(
                'Le barème de la saison a changé depuis le paiement (recalcul %s € ≠ montant réglé %s €) : à régulariser à la main.',
                number_format($check->total(), 2, ',', ' '),
                number_format((float) $order['amount'], 2, ',', ' '),
            ));
        }

        $amount = round((float) $order['amount'] - $granted->total(), 2);
        if ($amount <= 0) {
            return $no('Le tarif accordé n\'est pas inférieur au tarif réglé : rien à rembourser.');
        }

        return ['eligible' => true, 'amount' => $amount, 'problem' => null, 'existing' => null];
    }

    /**
     * Issues the avoir. Returns the existing one if there already is one, or
     * null when assess() says it cannot be issued (the caller shows the reason).
     */
    public function issue(array $order, string $grantedPricingResidence, string $reason, string $issuedBy): ?array
    {
        $assessment = $this->assess($order, $grantedPricingResidence);
        if ($assessment['existing'] !== null) {
            return $assessment['existing'];
        }
        if (!$assessment['eligible']) {
            return null;
        }

        $invoice = $this->invoices->findByOrderId((int) $order['id']);
        $inputs = $this->quoteInputs($order);
        if ($invoice === null || $inputs === null) {
            return null;
        }

        try {
            $issuedAt = new DateTimeImmutable();
            $allocation = $this->numbers->allocateCreditNote($issuedAt);
            $billing = $this->billingFor($order);
            $grantedLabel = $grantedPricingResidence === PricingService::RESIDENCE_GARENNOIS ? 'Garennois' : 'Hors commune';

            $lines = [[
                'description' => 'Régularisation — tarif ' . $grantedLabel . ' accordé à titre exceptionnel'
                    . ' (facture ' . $invoice['number'] . ')',
                'blurb'       => $reason,
                'quantity'    => 1,
                'unitPrice'   => $assessment['amount'],
                'reduc'       => '0',
                'amount'      => $assessment['amount'],
            ]];

            $pdfPath = $this->pdf->generateCreditNote(
                $allocation,
                $issuedAt,
                (string) $invoice['number'],
                $assessment['amount'],
                $billing['name'],
                $billing['address'],
                $lines,
                $inputs['season'],
            );

            $creditNote = $this->creditNotes->create(
                (int) $order['id'],
                (int) $invoice['id'],
                $allocation,
                $assessment['amount'],
                $reason,
                $pdfPath,
                $issuedBy,
                $issuedAt,
            );

            $this->logger->info('credit_note', 'Avoir émis', [
                'order_id' => $order['id'], 'number' => $allocation['number'], 'amount' => $assessment['amount'],
            ]);

            return $creditNote;
        } catch (\Throwable $e) {
            $this->logger->error('credit_note', 'Émission d\'avoir échouée', [
                'order_id' => $order['id'], 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** @return array{filename:string, content:string, mime:string} Mailer attachment shape */
    public function attachmentFor(array $creditNote): array
    {
        return [
            'filename' => 'Avoir-' . $creditNote['number'] . '.pdf',
            'content'  => (string) file_get_contents($this->uploadsDir . '/' . $creditNote['pdf_path']),
            'mime'     => 'application/pdf',
        ];
    }

    /**
     * The order this member's season was settled with, if any — the thing an
     * avoir would be issued against. member_formulas is the join: it is the one
     * table that ties (season, member) to the order that fulfilled it.
     */
    public function settledOrderFor(int $seasonStartYear, int $bjUserId): ?array
    {
        return $this->orders->findFulfilledForSeason($seasonStartYear, $bjUserId);
    }

    /**
     * Rebuilds the arguments the original quote was computed from, so it can be
     * re-run at another grid. $order['created_at'] stands in for "now" — the
     * prorata must be the one that applied on the day they paid, not today's.
     *
     * @return ?array{subscriptionKey:string, premiere:bool, season:Season, joinDate:?DateTimeImmutable,
     *                isCouple:bool, people:array, lessonsCount:int, summerPack:bool,
     *                studentDiscount:bool, promo:?array}
     */
    private function quoteInputs(array $order): ?array
    {
        $createdAt = new DateTimeImmutable((string) $order['created_at']);
        $studentDiscount = (bool) ($order['student_discount'] ?? false);
        // Mutually exclusive by construction in both flows — see PricingService::quote().
        $promo = $studentDiscount ? null : $this->promoFor($order);

        if ((string) $order['kind'] === 'renewal') {
            $meta = json_decode((string) ($order['meta'] ?? '{}'), true) ?: [];
            if (($meta['subscriptionType'] ?? '') === '') {
                return null;
            }
            $isCouple = (bool) ($meta['isCouple'] ?? false);
            return [
                'subscriptionKey' => (string) $meta['subscriptionType'],
                'premiere'        => false,
                'season'          => new Season((int) $meta['seasonStartYear']),
                // RenewalController::quoteFor() passes "now" unconditionally.
                'joinDate'        => $createdAt,
                'isCouple'        => $isCouple,
                'people'          => $isCouple
                    ? [
                        ['competitor' => (bool) ($meta['competitor'] ?? false), 'licenceRemoved' => (bool) ($meta['licenceRemoved'] ?? false)],
                        ['competitor' => (bool) ($meta['partnerCompetitor'] ?? false), 'licenceRemoved' => (bool) ($meta['partnerLicenceRemoved'] ?? false)],
                    ]
                    : [['competitor' => (bool) ($meta['competitor'] ?? false), 'licenceRemoved' => (bool) ($meta['licenceRemoved'] ?? false)]],
                'lessonsCount'    => (int) ($meta['lessons'] ?? 0),
                'summerPack'      => !empty($meta['lateSettlement']),
                'studentDiscount' => $studentDiscount,
                'promo'           => $promo,
            ];
        }

        if ((string) $order['kind'] === 'join' && $order['application_id'] !== null) {
            $app = $this->applications->findById((int) $order['application_id']);
            if ($app === null || (string) $app['subscription_type'] === '') {
                return null;
            }
            $people = $this->applications->people((int) $app['id']);
            $isCouple = (bool) $app['is_couple'];
            $season = new Season((int) $app['season_start_year']);
            return [
                'subscriptionKey' => (string) $app['subscription_type'],
                'premiere'        => true,
                'season'          => $season,
                // PaymentController::quoteFor() only prorates inside the season.
                'joinDate'        => $season->contains($createdAt) ? $createdAt : null,
                'isCouple'        => $isCouple,
                'people'          => $isCouple
                    ? [
                        ['competitor' => (bool) $people[1]['competitor'], 'licenceRemoved' => (bool) $people[1]['licence_removed']],
                        ['competitor' => (bool) ($people[2]['competitor'] ?? false), 'licenceRemoved' => (bool) ($people[2]['licence_removed'] ?? false)],
                    ]
                    : [['competitor' => (bool) $people[1]['competitor'], 'licenceRemoved' => (bool) $people[1]['licence_removed']]],
                'lessonsCount'    => (int) $app['lessons_count'],
                'summerPack'      => (bool) $app['summer_pack'],
                'studentDiscount' => $studentDiscount,
                'promo'           => $promo,
            ];
        }

        return null; // credits / lessons orders have no residence dimension
    }

    private function quoteAt(array $inputs, string $pricingResidence): Quote
    {
        return $this->pricing->quote(
            $inputs['subscriptionKey'],
            $pricingResidence,
            premiere: $inputs['premiere'],
            season: $inputs['season'],
            joinDate: $inputs['joinDate'],
            isCouple: $inputs['isCouple'],
            people: $inputs['people'],
            lessonsCount: $inputs['lessonsCount'],
            summerPack: $inputs['summerPack'],
            studentDiscount: $inputs['studentDiscount'],
            promo: $inputs['promo'],
        );
    }

    /** @return ?array{code:string, kind:string, value:float} */
    private function promoFor(array $order): ?array
    {
        if (empty($order['promo_code_id'])) {
            return null;
        }
        $promo = $this->promoCodes->findById((int) $order['promo_code_id']);
        if ($promo === null) {
            return null;
        }
        return ['code' => (string) $promo['code'], 'kind' => (string) $promo['kind'], 'value' => (float) $promo['value']];
    }

    /** @return array{name:string, address:array{address:string, postalcode:string, city:string}} */
    private function billingFor(array $order): array
    {
        $bjUserId = (int) $order['bj_user_id'];
        if ($bjUserId === 0 && $order['application_id'] !== null) {
            $people = $this->applications->people((int) $order['application_id']);
            $bjUserId = (int) ($people[1]['bj_user_id'] ?? 0);
        }
        if ($bjUserId === 0) {
            return ['name' => '', 'address' => ['address' => '', 'postalcode' => '', 'city' => '']];
        }

        $user = $this->bj->get('users/' . $bjUserId)['user'] ?? [];
        return [
            'name'    => trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')),
            'address' => [
                'address'    => (string) ($user['address'] ?? ''),
                'postalcode' => (string) ($user['postalcode'] ?? ''),
                'city'       => (string) ($user['city'] ?? ''),
            ],
        ];
    }
}
