<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ApplicationRepository;
use App\Repository\OrderRepository;
use App\Service\BankDetailsService;
use App\Service\Mailer;
use App\Service\OrderBreakdownService;
use App\Service\PaymentSettlementService;
use App\Service\PricingService;
use App\Service\PromoCodeService;
use App\Service\Season;
use App\Service\SumUpService;
use App\Support\Csrf;
use App\Support\Logger;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/**
 * Join payment: cart (with optional licence removal), SumUp hosted checkout,
 * return URL and webhook — both re-verify the checkout status via the SumUp
 * API before fulfillment, which is idempotent via the order state machine.
 */
final class PaymentController
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly OrderRepository $orders,
        private readonly PricingService $pricing,
        private readonly PromoCodeService $promoCodes,
        private readonly SumUpService $sumup,
        private readonly OrderBreakdownService $breakdown,
        private readonly PaymentSettlementService $settlement,
        private readonly Mailer $mailer,
        private readonly BankDetailsService $bankDetails,
        private readonly PhpRenderer $renderer,
        private readonly Logger $logger,
        private readonly bool $debug,
        private readonly string $clubEmail,
    ) {
    }

    private const array PAYABLE_STATUSES = ['validated', 'awaiting_payment'];

    // ── Cart ─────────────────────────────────────────────────────────────

    public function showCart(Request $request, Response $response, array $args): Response
    {
        $app = $this->applications->findByToken($args['token']);
        if ($app === null) {
            return $response->withStatus(302)->withHeader('Location', '/');
        }
        if (!in_array($app['status'], self::PAYABLE_STATUSES, true)) {
            return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/confirmation');
        }
        $pending = $this->orders->findAwaitingPromoApprovalByApplication((int) $app['id'])
            ?? $this->orders->findAwaitingStudentApprovalByApplication((int) $app['id']);
        if ($pending !== null) {
            return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . $pending['checkout_reference']);
        }
        return $this->renderCart($response, $app, []);
    }

    /**
     * Cart options: group-lesson enrolments and promo code. Licence removal
     * is decided once, at the wizard's Licence step (before the application
     * is submitted for admin review) — it's locked in by the time an admin
     * has validated the application and this payment step becomes
     * reachable, not something to reopen here without that review having
     * happened.
     */
    public function updateOptions(Request $request, Response $response, array $args): Response
    {
        $app = $this->applications->findByToken($args['token']);
        $body = (array) $request->getParsedBody();
        if ($app === null || !in_array($app['status'], self::PAYABLE_STATUSES, true) || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/');
        }
        if ($this->orders->findAwaitingPromoApprovalByApplication((int) $app['id']) !== null
            || $this->orders->findAwaitingStudentApprovalByApplication((int) $app['id']) !== null) {
            return $response->withStatus(302)->withHeader('Location', '/paiement/' . $app['token']);
        }

        // A student-discount request has its own admin-approval gate — no
        // promo code alongside it (the cart template hides that field too).
        $promoCode = !empty($app['student_discount_requested']) ? '' : strtoupper(trim((string) ($body['promo_code'] ?? '')));
        if ($promoCode !== '') {
            $resolved = $this->promoCodes->resolve($promoCode, 'join');
            if (!$resolved['ok']) {
                return $this->renderCart($response, $app, [$this->promoCodes->errorMessage((string) $resolved['error'])]);
            }
        }

        $isCouple = (bool) $app['is_couple'];
        $fields = ['promo_code' => $promoCode];
        $subscription = $this->pricing->subscription($app['subscription_type'], new Season((int) $app['season_start_year']));
        if ($subscription['audience'] !== 'jeune' && !$app['summer_pack']) {
            $fields['lessons_count'] = min((int) !empty($body['lessons_1']), 1)
                + ($isCouple ? (int) !empty($body['lessons_2']) : 0);
        }
        $this->applications->update((int) $app['id'], $fields);

        return $response->withStatus(302)->withHeader('Location', '/paiement/' . $app['token']);
    }

    public function startCheckout(Request $request, Response $response, array $args): Response
    {
        $app = $this->applications->findByToken($args['token']);
        $body = (array) $request->getParsedBody();
        if ($app === null || !in_array($app['status'], self::PAYABLE_STATUSES, true) || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/');
        }

        $isStudent = !$app['is_couple'] && !empty($app['student_discount_requested']);

        // Re-resolve defensively: the stored code may have expired or hit its
        // use limit since it was applied in updateOptions() — block rather
        // than silently drop the discount or let a stale code through.
        $promoCode = $isStudent ? '' : (string) ($app['promo_code'] ?? '');
        $promoResolved = $this->promoCodes->resolve($promoCode, 'join');
        if ($promoCode !== '' && !$promoResolved['ok']) {
            return $this->renderCart($response, $app, [
                $this->promoCodes->errorMessage((string) $promoResolved['error']) . ' Merci de retirer le code promo pour continuer.',
            ]);
        }

        // A promo code or a student-discount request each need admin approval
        // before any payment link is issued — see AdminPromoCodeController /
        // AdminOpsController::decideStudentDiscount(). Redirect to an existing
        // awaiting-approval order instead of creating a duplicate one if the
        // applicant clicks "Payer" again (or comes back) while it's still
        // undecided.
        $requiresApproval = ($promoCode !== '' && $promoResolved['ok']) || $isStudent;
        if ($requiresApproval) {
            $pending = $isStudent
                ? $this->orders->findAwaitingStudentApprovalByApplication((int) $app['id'])
                : $this->orders->findAwaitingPromoApprovalByApplication((int) $app['id']);
            if ($pending !== null) {
                return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . $pending['checkout_reference']);
            }
        }

        // Bank transfer is unavailable while a promo code needs admin approval
        // first — combining both admin-gated flows on one order isn't worth
        // the complexity; the cart template already hides the option in that
        // case, this is the server-side backstop.
        $paymentMethod = !$requiresApproval && ($body['payment_method'] ?? 'online') === 'bank_transfer' ? 'bank_transfer' : 'online';

        if ($paymentMethod === 'bank_transfer') {
            // Resume our own still-open wait rather than duplicating it.
            $pendingTransfer = $this->orders->findAwaitingBankTransferByApplication((int) $app['id']);
            if ($pendingTransfer !== null) {
                return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . $pendingTransfer['checkout_reference']);
            }
            // The applicant may be switching away from an abandoned online
            // attempt — honor it if it actually succeeded in the background,
            // otherwise close it out rather than silently resuming its SumUp
            // checkout and overriding the choice they just made.
            $switchUrl = $this->settlement->abandonForSwitch($this->orders->findOpenOrderByApplication((int) $app['id']));
            if ($switchUrl !== null) {
                return $response->withStatus(302)->withHeader('Location', $switchUrl);
            }
        } else {
            // Mirror image: switching away from an abandoned bank-transfer
            // wait to pay online instead — cancel it so it doesn't linger
            // unresolved once the applicant has moved on.
            $pendingTransfer = $this->orders->findAwaitingBankTransferByApplication((int) $app['id']);
            if ($pendingTransfer !== null) {
                $this->orders->transition((int) $pendingTransfer['id'], 'awaiting_bank_transfer', 'canceled');
            }

            // Don't spawn a duplicate order/checkout if the applicant already has one
            // open (abandoned checkout, or a page error after a charge that actually
            // went through) — see PaymentSettlementService::resumeIfOpen().
            $resumeUrl = $this->settlement->resumeIfOpen($this->orders->findOpenOrderByApplication((int) $app['id']));
            if ($resumeUrl !== null) {
                return $response->withStatus(302)->withHeader('Location', $resumeUrl);
            }
        }

        $quote = $this->quoteFor($app);
        $discountLine = self::discountLine($quote);
        $order = $this->orders->create(
            'join',
            (int) $app['id'],
            0,
            $app['email'],
            $quote->total(),
            array_map(fn ($l) => [
                'type' => $l->type, 'label' => $l->label, 'amount' => $l->amount,
                'baseAmount' => $l->baseAmount, 'personIndex' => $l->personIndex,
            ], $quote->lines),
            promoCodeId: $promoResolved['promo']['id'] ?? null,
            discountAmount: $discountLine !== null ? -$discountLine->amount : 0.0,
            paymentMethod: $paymentMethod,
            studentDiscount: $isStudent,
        );

        if ($requiresApproval) {
            $this->orders->transition((int) $order['id'], 'pending', $isStudent ? 'awaiting_student_approval' : 'awaiting_promo_approval');
            $this->applications->setStatus((int) $app['id'], 'awaiting_payment');
            return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . $order['checkout_reference']);
        }

        if ($paymentMethod === 'bank_transfer') {
            $this->orders->transition((int) $order['id'], 'pending', 'awaiting_bank_transfer');
            $this->applications->setStatus((int) $app['id'], 'awaiting_payment');
            $this->sendBankTransferInstructions($order);
            return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . $order['checkout_reference']);
        }

        $returnUrl = $this->baseUrl($request) . '/paiement/retour/' . $order['checkout_reference'];
        try {
            $checkout = $this->sumup->createCheckout(
                $order['checkout_reference'],
                (float) $order['amount'],
                'Adhésion Bad & Squash — demande #' . $app['id'],
                $returnUrl,
            );
        } catch (\RuntimeException $e) {
            return $this->renderCart($response, $app, [$e->getMessage()]);
        }

        $this->orders->update((int) $order['id'], ['checkout_id' => $checkout['checkout_id']]);
        $this->applications->setStatus((int) $app['id'], 'awaiting_payment');

        return $response->withStatus(302)->withHeader('Location', $checkout['url']);
    }

    // ── Return URL & webhook ─────────────────────────────────────────────

    public function paymentReturn(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findByReference($args['reference']);
        if ($order === null) {
            return $response->withStatus(302)->withHeader('Location', '/');
        }

        // Nothing to settle yet — no SumUp checkout was ever created for these
        // (a pending promo/student approval, or an awaiting bank-transfer confirmation).
        if (!in_array($order['status'], ['awaiting_promo_approval', 'awaiting_bank_transfer', 'awaiting_student_approval'], true)) {
            $this->settlement->settle($order);
            $order = $this->orders->findByReference($args['reference']);
        }

        // RenewalController's coverage check is BJ-date-only (no local override —
        // see RenewalService's docblock), so a member navigating straight back to
        // /espace/renouvellement can momentarily hit BJ before it's caught up with
        // this write. A short-lived marker lets that page show a "confirming…"
        // wait state instead of the renewal form again. Session-only: settleOrder()
        // is also reachable from webhook(), which has no browser session to write.
        if ($order['kind'] === 'renewal' && $order['status'] === 'fulfilled') {
            $meta = json_decode((string) ($order['meta'] ?? '{}'), true) ?: [];
            $_SESSION['renewal_just_paid'] = [
                'seasonStartYear' => (int) ($meta['seasonStartYear'] ?? 0),
                'until'           => time() + 30,
                'attempts'        => 0,
            ];
        }

        return $this->renderer->render($response, 'pages/payment_result.php', [
            'title'     => 'Paiement',
            'csrf'      => Csrf::token(),
            'order'     => $order,
            'breakdown' => $this->breakdown->forOrder($order),
            'bank'      => $this->bankDetails->current(),
            'backUrl'   => $order['status'] === 'awaiting_bank_transfer' ? $this->paymentMethodBackUrl($order) : null,
        ]);
    }

    /** Where "← Précédent, je préfère payer en ligne" sends the payer back to choose again. */
    private function paymentMethodBackUrl(array $order): string
    {
        if ($order['kind'] === 'join') {
            $app = $order['application_id'] !== null ? $this->applications->findById((int) $order['application_id']) : null;
            return $app !== null ? '/paiement/' . $app['token'] : '/';
        }
        return '/espace/renouvellement';
    }

    /**
     * The payer says they've sent the transfer — doesn't fulfill anything on
     * its own (only the admin's own confirmation against the bank statement
     * does that, see AdminOpsController::decideBankTransfer), just gives the
     * admin a signal of when to go look instead of blind polling.
     */
    public function claimBankTransfer(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $order = $this->orders->findByReference($args['reference']);
        if (!Csrf::validate($body['csrf'] ?? null) || $order === null || $order['status'] !== 'awaiting_bank_transfer') {
            return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . ($args['reference'] ?? ''));
        }

        $this->orders->update((int) $order['id'], ['bank_transfer_claimed_at' => date('Y-m-d H:i:s')]);

        $kindLabel = $order['kind'] === 'join' ? 'une adhésion' : 'un renouvellement';
        $this->mailer->send(
            $this->clubEmail,
            'Virement signalé — commande #' . $order['id'],
            '<p>Un adhérent indique avoir effectué un virement pour ' . $kindLabel . ' (commande #' . (int) $order['id'] . ', '
            . number_format((float) $order['amount'], 2, ',', ' ') . ' €).</p>'
            . '<p>Référence attendue : ' . htmlspecialchars(OrderRepository::bankTransferReference($order), ENT_QUOTES) . '</p>'
            . '<p><a href="/admin/virements">Vérifier sur le relevé bancaire</a></p>',
            'bank_transfer_claimed',
        );

        return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . $order['checkout_reference']);
    }

    private function sendBankTransferInstructions(array $order): void
    {
        $bank = $this->bankDetails->current();
        $this->mailer->send(
            (string) $order['email'],
            'Instructions pour votre virement — Bad & Squash',
            '<p>Bonjour,</p>'
            . '<p>Pour finaliser votre adhésion, merci d\'effectuer un virement de <strong>' . number_format((float) $order['amount'], 2, ',', ' ') . ' €</strong> aux coordonnées suivantes :</p>'
            . '<p>' . htmlspecialchars($bank['name'], ENT_QUOTES) . '<br>'
            . 'IBAN : ' . htmlspecialchars($bank['iban'], ENT_QUOTES) . '<br>'
            . 'BIC : ' . htmlspecialchars($bank['bic'], ENT_QUOTES) . '</p>'
            . '<p><strong>Référence à indiquer impérativement : ' . htmlspecialchars(OrderRepository::bankTransferReference($order), ENT_QUOTES) . '</strong> (sans cette référence, le club ne peut pas identifier votre virement).</p>'
            . '<p>Votre adhésion sera finalisée dès que le club aura constaté la réception du virement — comptez quelques jours ouvrés.</p>',
            'bank_transfer_instructions',
        );
    }

    public function webhook(Request $request, Response $response, array $args = []): Response
    {
        $payload = json_decode((string) $request->getBody(), true);
        $checkoutId = (string) ($payload['id'] ?? ($payload['checkout_id'] ?? ''));
        // SumUp's real payload only carries the checkout id (see class docblock note
        // on the route), not our reference — the path segment is the reliable source.
        $reference = (string) ($args['reference'] ?? ($payload['checkout_reference'] ?? ''));

        $order = null;
        if ($reference !== '') {
            $order = $this->orders->findByReference($reference);
        }
        if ($order === null && $checkoutId !== '') {
            $order = $this->orders->findByCheckoutId($checkoutId);
        }

        if ($order !== null) {
            $this->settlement->settle($order);
        } else {
            $this->logger->info('payment', 'Webhook for unknown order', ['payload' => $payload]);
        }

        // Always 200 so SumUp does not retry indefinitely on stale events.
        $response->getBody()->write('{"ok":true}');
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ── Dev-mode payment simulator (no SumUp credentials) ────────────────

    public function devCheckout(Request $request, Response $response, array $args): Response
    {
        if (!$this->debug || !$this->sumup->isDevMode()) {
            return $response->withStatus(404);
        }
        $order = $this->orders->findByReference($args['reference']);
        if ($order === null) {
            return $response->withStatus(404);
        }
        return $this->renderer->render($response, 'pages/payment_dev.php', [
            'title' => 'Paiement (simulation)',
            'order' => $order,
            'csrf'  => Csrf::token(),
        ]);
    }

    public function devPay(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        if (!$this->debug || !$this->sumup->isDevMode() || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(404);
        }
        $order = $this->orders->findByReference($args['reference']);
        if ($order === null) {
            return $response->withStatus(404);
        }
        $this->orders->update((int) $order['id'], ['dev_paid' => 1]);
        return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . $order['checkout_reference']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function quoteFor(array $app): \App\Service\Quote
    {
        $season = new Season((int) $app['season_start_year']);
        $now = new DateTimeImmutable();
        $people = $this->applications->people((int) $app['id']);
        $isCouple = (bool) $app['is_couple'];
        $quotePeople = $isCouple
            ? [
                ['competitor' => (bool) $people[1]['competitor'], 'licenceRemoved' => (bool) $people[1]['licence_removed']],
                ['competitor' => (bool) ($people[2]['competitor'] ?? false), 'licenceRemoved' => (bool) ($people[2]['licence_removed'] ?? false)],
            ]
            : [['competitor' => (bool) $people[1]['competitor'], 'licenceRemoved' => (bool) $people[1]['licence_removed']]];

        $isStudent = !$isCouple && (bool) $app['student_discount_requested'];

        // Never throws on a stale/invalid stored code — this also feeds plain
        // cart display, e.g. right after a failed updateOptions(). Mutually
        // exclusive with the student discount — see quoteFor()'s isStudent.
        $promo = $isStudent ? null : $this->promoCodes->resolve((string) ($app['promo_code'] ?? ''), 'join')['promo'];

        return $this->pricing->quote(
            $app['subscription_type'],
            $app['residence'],
            premiere: true,
            season: $season,
            joinDate: $season->contains($now) ? $now : null,
            isCouple: $isCouple,
            people: $quotePeople,
            lessonsCount: (int) $app['lessons_count'],
            midiResidencyOverride: (bool) $app['midi_residency_override'],
            summerPack: (bool) $app['summer_pack'],
            studentDiscount: $isStudent,
            promo: $promo,
        );
    }

    private static function discountLine(\App\Service\Quote $quote): ?\App\Service\CartLine
    {
        foreach ($quote->lines as $line) {
            if ($line->type === 'discount') {
                return $line;
            }
        }
        return null;
    }

    private function renderCart(Response $response, array $app, array $errors): Response
    {
        return $this->renderer->render($response, 'pages/payment_cart.php', [
            'title'        => 'Paiement de l\'adhésion',
            'csrf'         => Csrf::token(),
            'app'          => $app,
            'people'       => $this->applications->people((int) $app['id']),
            'subscription' => $this->pricing->subscription($app['subscription_type'], new Season((int) $app['season_start_year'])),
            'quote'        => $this->quoteFor($app),
            'errors'       => $errors,
        ]);
    }

    private function baseUrl(Request $request): string
    {
        $uri = $request->getUri();
        return $uri->getScheme() . '://' . $uri->getAuthority();
    }
}
