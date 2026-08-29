<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AuditLogRepository;
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
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    /**
     * Verifies the checkout with SumUp and, if paid, fulfills exactly once
     * (atomic pending→paid then paid→fulfilling transitions).
     *
     * 'failed' is treated as recoverable, not terminal: SumUp's hosted
     * checkout lets a payer retry with a different card after a decline, on
     * the *same* checkout — a member can genuinely pay successfully after an
     * earlier attempt already got this order marked 'failed' (see the
     * Thibaud Zaban incident: declined at 20:15:38, marked failed at
     * 20:16:22, paid on retry at 20:18:40 — with only a pending→paid
     * transition, that payment could never be picked back up). So PAID is
     * checked first and can promote from either 'pending' or 'failed'.
     */
    public function settle(array $order): void
    {
        if (in_array($order['status'], ['fulfilled', 'fulfilling'], true)) {
            return;
        }

        $checkout = $this->sumup->checkoutStatus($order);
        if ($checkout['status'] === 'PAID') {
            if (!$this->orders->transition((int) $order['id'], 'pending', 'paid')) {
                $this->orders->transition((int) $order['id'], 'failed', 'paid');
            }
        } elseif ($checkout['status'] === 'FAILED') {
            $this->orders->transition((int) $order['id'], 'pending', 'failed');
            return;
        } else {
            return;
        }

        $this->claimAndFulfill($order, function (array $o) use ($checkout): array {
            if ($checkout['transactionCode'] !== null) {
                $meta = json_decode((string) ($o['meta'] ?? '{}'), true) ?: [];
                $meta['transactionCode'] = $checkout['transactionCode'];
                $o['meta'] = json_encode($meta, JSON_UNESCAPED_UNICODE);
                $this->orders->update((int) $o['id'], ['meta' => $o['meta']]);
            }
            return $o;
        });
    }

    /**
     * Admin's manual confirmation that the transfer landed in the club's
     * account IS the payment verification here — there's no SumUp checkout
     * to check against, unlike settle(). Only acts on an order actually
     * still awaiting that confirmation (guards against a stale/double
     * submit of the admin form).
     */
    public function confirmBankTransfer(array $order): bool
    {
        if (!$this->orders->transition((int) $order['id'], 'awaiting_bank_transfer', 'paid')) {
            return false;
        }
        $this->orders->update((int) $order['id'], ['bank_transfer_confirmed_at' => date('Y-m-d H:i:s')]);
        $this->claimAndFulfill($order);
        return true;
    }

    /**
     * Shared tail once an order is confirmed 'paid' by whatever means: claims
     * paid→fulfilling (the idempotency lock), fulfills, marks 'fulfilled', or
     * rolls back to 'paid' on failure so a retry can pick it up. $beforeFulfill
     * lets settle() attach SumUp's transactionCode to meta first — the one
     * SumUp-specific step in what's otherwise identical for both payment methods.
     * Every order has a checkout_reference regardless of payment method (set
     * at creation), so findByReference() works the same for both here.
     */
    private function claimAndFulfill(array $order, ?callable $beforeFulfill = null): void
    {
        if (!$this->orders->transition((int) $order['id'], 'paid', 'fulfilling')) {
            return; // another request is fulfilling or already done
        }

        try {
            $order = $this->orders->findByReference($order['checkout_reference']);
            if ($beforeFulfill !== null) {
                $order = $beforeFulfill($order);
            }
            $this->fulfillment->fulfill($order);
            $this->orders->update((int) $order['id'], ['status' => 'fulfilled', 'fulfilled_at' => date('Y-m-d H:i:s')]);
            $this->auditLog->log('system', 'order.fulfilled', 'order', (string) $order['id'], [
                'kind' => $order['kind'], 'amount' => $order['amount'],
            ]);
        } catch (Throwable $e) {
            $this->logger->error('payment', 'Fulfillment failed', [
                'order_id' => (int) $order['id'], 'error' => $e->getMessage(),
            ]);
            $this->auditLog->log('system', 'order.fulfillment_failed', 'order', (string) $order['id'], [
                'kind' => $order['kind'], 'error' => $e->getMessage(),
            ]);
            // Back to 'paid' so the next webhook/return/retry retries fulfillment.
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

    /**
     * Like resumeIfOpen(), but for a caller about to create an order via a
     * DIFFERENT payment method than $existing was on — the member is
     * actively switching away from it, not returning to finish it. A
     * checkout that turns out to have already succeeded in the background
     * still short-circuits to the confirmation page (they already paid,
     * nothing left to switch); anything else (still open, failed,
     * uncheckable) is simply closed out so a fresh order can be created
     * cleanly, instead of resuming the old checkout URL and silently
     * overriding the method they just chose.
     */
    public function abandonForSwitch(?array $existing): ?string
    {
        if ($existing === null) {
            return null;
        }

        try {
            $checkout = $this->sumup->checkoutStatus($existing);
        } catch (Throwable $e) {
            $this->logger->error('payment', 'Could not verify existing checkout while switching payment method, closing it out', [
                'order_id' => (int) $existing['id'], 'error' => $e->getMessage(),
            ]);
            $this->orders->transition((int) $existing['id'], 'pending', 'failed');
            return null;
        }
        if ($checkout['status'] === 'PAID') {
            $this->settle($existing);
            return '/paiement/retour/' . $existing['checkout_reference'];
        }

        $this->orders->transition((int) $existing['id'], 'pending', 'failed');
        return null;
    }
}
