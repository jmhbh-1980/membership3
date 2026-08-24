<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ApplicationRepository;
use App\Repository\OrderRepository;
use App\Service\FulfillmentService;
use App\Service\PricingService;
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
        private readonly SumUpService $sumup,
        private readonly FulfillmentService $fulfillment,
        private readonly PhpRenderer $renderer,
        private readonly Logger $logger,
        private readonly bool $debug,
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
        return $this->renderCart($response, $app, []);
    }

    /**
     * Cart options: group-lesson enrolments only. Licence removal is decided
     * once, at the wizard's Licence step (before the application is
     * submitted for admin review) — it's locked in by the time an admin has
     * validated the application and this payment step becomes reachable, not
     * something to reopen here without that review having happened.
     */
    public function updateOptions(Request $request, Response $response, array $args): Response
    {
        $app = $this->applications->findByToken($args['token']);
        $body = (array) $request->getParsedBody();
        if ($app === null || !in_array($app['status'], self::PAYABLE_STATUSES, true) || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/');
        }

        $isCouple = (bool) $app['is_couple'];
        $fields = [];
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

        $quote = $this->quoteFor($app);
        $order = $this->orders->create(
            'join',
            (int) $app['id'],
            0,
            $app['email'],
            $quote->total(),
            array_map(fn ($l) => ['type' => $l->type, 'label' => $l->label, 'amount' => $l->amount], $quote->lines),
        );

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

        $this->settleOrder($order);
        $order = $this->orders->findByReference($args['reference']);

        return $this->renderer->render($response, 'pages/payment_result.php', [
            'title' => 'Paiement',
            'order' => $order,
        ]);
    }

    public function webhook(Request $request, Response $response): Response
    {
        $payload = json_decode((string) $request->getBody(), true);
        $checkoutId = (string) ($payload['id'] ?? ($payload['checkout_id'] ?? ''));
        $reference = (string) ($payload['checkout_reference'] ?? '');

        $order = null;
        if ($reference !== '') {
            $order = $this->orders->findByReference($reference);
        }
        if ($order === null && $checkoutId !== '') {
            $order = $this->orders->findByCheckoutId($checkoutId);
        }

        if ($order !== null) {
            $this->settleOrder($order);
        } else {
            $this->logger->info('payment', 'Webhook for unknown order', ['payload' => $payload]);
        }

        // Always 200 so SumUp does not retry indefinitely on stale events.
        $response->getBody()->write('{"ok":true}');
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Verifies the checkout with SumUp and, if paid, fulfills exactly once
     * (atomic pending→paid then paid→fulfilling transitions).
     */
    private function settleOrder(array $order): void
    {
        if (in_array($order['status'], ['fulfilled', 'fulfilling'], true)) {
            return;
        }

        $status = $this->sumup->checkoutStatus($order);
        if ($status === 'FAILED') {
            $this->orders->transition((int) $order['id'], 'pending', 'failed');
            return;
        }
        if ($status !== 'PAID') {
            return;
        }

        $this->orders->transition((int) $order['id'], 'pending', 'paid');
        if (!$this->orders->transition((int) $order['id'], 'paid', 'fulfilling')) {
            return; // another request is fulfilling or already done
        }

        try {
            $order = $this->orders->findByReference($order['checkout_reference']);
            $this->fulfillment->fulfill($order);
            $this->orders->update((int) $order['id'], ['status' => 'fulfilled', 'fulfilled_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            $this->logger->error('payment', 'Fulfillment failed', [
                'order_id' => (int) $order['id'], 'error' => $e->getMessage(),
            ]);
            // Back to 'paid' so the next webhook/return retries fulfillment.
            $this->orders->transition((int) $order['id'], 'fulfilling', 'paid');
        }
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
        );
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
