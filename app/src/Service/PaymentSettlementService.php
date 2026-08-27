<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OrderRepository;
use App\Support\Logger;
use Throwable;

/**
 * Verifies a checkout with SumUp and fulfills exactly once. Split out of
 * PaymentController so the same settlement logic also backs resumeIfOpen()
 * below, called from startCheckout() in the join/renewal/credits/lessons
 * controllers before they create a new order.
 */
final class PaymentSettlementService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly SumUpService $sumup,
        private readonly FulfillmentService $fulfillment,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Verifies the checkout with SumUp and, if paid, fulfills exactly once
     * (atomic pending→paid then paid→fulfilling transitions).
     */
    public function settle(array $order): void
    {
        if (in_array($order['status'], ['fulfilled', 'fulfilling'], true)) {
            return;
        }

        $checkout = $this->sumup->checkoutStatus($order);
        if ($checkout['status'] === 'FAILED') {
            $this->orders->transition((int) $order['id'], 'pending', 'failed');
            return;
        }
        if ($checkout['status'] !== 'PAID') {
            return;
        }

        $this->orders->transition((int) $order['id'], 'pending', 'paid');
        if (!$this->orders->transition((int) $order['id'], 'paid', 'fulfilling')) {
            return; // another request is fulfilling or already done
        }

        try {
            $order = $this->orders->findByReference($order['checkout_reference']);
            if ($checkout['transactionCode'] !== null) {
                $meta = json_decode((string) ($order['meta'] ?? '{}'), true) ?: [];
                $meta['transactionCode'] = $checkout['transactionCode'];
                $order['meta'] = json_encode($meta, JSON_UNESCAPED_UNICODE);
                $this->orders->update((int) $order['id'], ['meta' => $order['meta']]);
            }
            $this->fulfillment->fulfill($order);
            $this->orders->update((int) $order['id'], ['status' => 'fulfilled', 'fulfilled_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            $this->logger->error('payment', 'Fulfillment failed', [
                'order_id' => (int) $order['id'], 'error' => $e->getMessage(),
            ]);
            // Back to 'paid' so the next webhook/return retries fulfillment.
            $this->orders->transition((int) $order['id'], 'fulfilling', 'paid');
        }
    }

    /**
     * Called from startCheckout() before creating a new order, when the
     * member/application might already have one open. Rather than assuming a
     * still-'pending' order was simply abandoned, this checks its real,
     * current status with SumUp — a hosted-checkout page can fail to confirm
     * back to the payer even though the underlying charge actually went
     * through, and treating that as "never paid" would risk both a genuine
     * double charge and, once the first checkout's webhook eventually
     * arrives, double-fulfilling it (a second BJ account, a second invoice —
     * see FulfillmentService's duplicate-payment guards, which are the
     * second line of defense if a duplicate is created anyway).
     *
     * @return string|null a URL to redirect the payer to instead of creating
     *     a new order, or null when it's safe to proceed (nothing open, or
     *     the open one just got closed out as failed).
     */
    public function resumeIfOpen(?array $existing): ?string
    {
        if ($existing === null) {
            return null;
        }

        try {
            $checkout = $this->sumup->checkoutStatus($existing);
        } catch (Throwable $e) {
            // A genuinely expired/unknown checkout (SumUp errors on the lookup
            // itself) shouldn't trap the member behind a stale order forever —
            // close it out locally and let a fresh one be created.
            $this->logger->error('payment', 'Could not verify existing checkout, closing it out', [
                'order_id' => (int) $existing['id'], 'error' => $e->getMessage(),
            ]);
            $this->orders->transition((int) $existing['id'], 'pending', 'failed');
            return null;
        }
        if ($checkout['status'] === 'FAILED') {
            $this->orders->transition((int) $existing['id'], 'pending', 'failed');
            return null;
        }
        if ($checkout['status'] === 'PAID') {
            $this->settle($existing);
            return '/paiement/retour/' . $existing['checkout_reference'];
        }

        // Still genuinely open — resume the same checkout rather than duplicating it.
        return $checkout['url'] ?? ('/paiement/retour/' . $existing['checkout_reference']);
    }
}
