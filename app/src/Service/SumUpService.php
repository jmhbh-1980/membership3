<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Logger;
use RuntimeException;

/**
 * SumUp hosted-checkout integration (https://developer.sumup.com).
 * Uses the Checkouts API directly over cURL: create a checkout with
 * hosted_checkout enabled, redirect the member to the hosted page, then
 * verify the checkout status server-side (never trust the redirect alone).
 *
 * Dev mode (no API key configured): checkouts are simulated by an internal
 * page so the whole payment→fulfillment chain stays testable locally.
 *
 * Installments (createCustomer/createTokenizingCheckout/chargeToken): a
 * SETUP_RECURRING_PAYMENT checkout cannot be completed through the plain
 * hosted-checkout redirect above — confirmed against the real SumUp sandbox
 * (the identical test card succeeds instantly on a normal checkout but is
 * rejected client-side, with zero transactions ever recorded server-side,
 * on a tokenizing one). Card-saving needs SumUp's own client-side Payment
 * Widget instead (see renewal_installment_pay.php) — this class only
 * creates/charges the checkout, it never sees a raw card number either way.
 */
class SumUpService
{
    private const string API_BASE = 'https://api.sumup.com/v0.1';

    public function __construct(
        private readonly array $config, // ['api_key' => ..., 'merchant_code' => ...]
        private readonly Logger $logger,
    ) {
    }

    public function isDevMode(): bool
    {
        return ($this->config['api_key'] ?? '') === '';
    }

    /**
     * Creates a checkout for an order.
     * @return array{checkout_id: string, url: string} the id and the page to redirect the payer to
     */
    public function createCheckout(string $reference, float $amount, string $description, string $returnUrl): array
    {
        if ($this->isDevMode()) {
            return ['checkout_id' => 'DEV-' . $reference, 'url' => '/paiement/dev/' . $reference];
        }

        $payload = [
            'checkout_reference' => $reference,
            'amount'             => round($amount, 2),
            'currency'           => 'EUR',
            'merchant_code'      => $this->config['merchant_code'] ?? '',
            'description'        => $description,
            'return_url'         => $returnUrl,
            'redirect_url'       => $returnUrl,
            'hosted_checkout'    => ['enabled' => true],
        ];
        $response = $this->request('POST', '/checkouts', $payload);

        $url = $response['hosted_checkout_url'] ?? '';
        if ($url === '' || ($response['id'] ?? '') === '') {
            $this->logger->error('sumup', 'Unexpected checkout response', ['response' => $response]);
            throw new RuntimeException('Création du paiement impossible, merci de réessayer.');
        }

        return ['checkout_id' => (string) $response['id'], 'url' => $url];
    }

    /**
     * Verifies the checkout with SumUp. transactionCode is SumUp's short
     * reference (e.g. "TAAA4ZFGBM3", shown on the payer's statement) — null
     * until a payment attempt has actually settled, and always null in dev
     * mode since there's no real transaction behind a simulated payment.
     * paymentToken is only ever present after a SETUP_RECURRING_PAYMENT
     * checkout (see createTokenizingCheckout()) completes.
     *
     * @return array{status: string, transactionCode: ?string, paymentToken: ?string}
     */
    public function checkoutStatus(array $order): array
    {
        if ($this->isDevMode()) {
            return [
                'status'          => $order['dev_paid'] ? 'PAID' : 'PENDING',
                'transactionCode' => null,
                'url'             => $order['dev_paid'] ? null : '/paiement/dev/' . $order['checkout_reference'],
                'paymentToken'    => $order['dev_paid'] ? 'DEV-TOKEN-' . $order['checkout_reference'] : null,
            ];
        }
        $response = $this->request('GET', '/checkouts/' . rawurlencode($order['checkout_id']));
        return [
            'status'          => strtoupper((string) ($response['status'] ?? 'PENDING')),
            'transactionCode' => $response['transaction_code'] ?? $response['transactions'][0]['transaction_code'] ?? null,
            'url'             => $response['hosted_checkout_url'] ?? null,
            'paymentToken'    => $response['payment_instrument']['token'] ?? null,
        ];
    }

    /** Registers a SumUp customer to later attach a tokenized card to. $customerId is ours to choose. */
    public function createCustomer(string $customerId): void
    {
        if ($this->isDevMode()) {
            return;
        }
        $this->request('POST', '/customers', ['customer_id' => $customerId]);
    }

    /**
     * Creates the checkout that will tokenize a card — the payer completes
     * it via SumUp's Payment Widget (mounted client-side against the
     * returned checkout_id, see renewal_installment_pay.php), never via the
     * plain hosted-checkout redirect this class uses elsewhere: confirmed
     * against SumUp's own sandbox that the redirect page rejects card entry
     * for a SETUP_RECURRING_PAYMENT checkout before it even reaches SumUp's
     * server. The widget still keeps raw card data off this server, exactly
     * like the redirect does for a normal checkout.
     *
     * @return array{checkout_id: string}
     */
    public function createTokenizingCheckout(string $reference, float $amount, string $description, string $customerId): array
    {
        if ($this->isDevMode()) {
            return ['checkout_id' => 'DEV-' . $reference];
        }

        $payload = [
            'checkout_reference' => $reference,
            'amount'             => round($amount, 2),
            'currency'           => 'EUR',
            'merchant_code'      => $this->config['merchant_code'] ?? '',
            'description'        => $description,
            'customer_id'        => $customerId,
            'purpose'            => 'SETUP_RECURRING_PAYMENT',
        ];
        $response = $this->request('POST', '/checkouts', $payload);
        if (($response['id'] ?? '') === '') {
            $this->logger->error('sumup', 'Unexpected tokenizing checkout response', ['response' => $response]);
            throw new RuntimeException('Création du paiement impossible, merci de réessayer.');
        }
        return ['checkout_id' => (string) $response['id']];
    }

    /**
     * Charges a previously tokenized card — pure server-to-server, no
     * redirect, no widget, nobody present. Per SumUp's docs the PUT
     * response's own status is not authoritative (can still read PENDING
     * even when the nested transaction already succeeded), so this
     * deliberately does not report a final outcome in production — the
     * caller must always confirm via checkoutStatus() afterward. In dev
     * mode there's nothing async to poll, so the outcome is resolved here
     * and now, using the exact amounts SumUp's own real sandbox treats as
     * an always-decline (11.00, 42.01, 42.76, 42.91) so the failure path is
     * reproducible without a separate flag.
     *
     * @return array{checkout_id: string, status: ?string} status is only ever non-null in dev mode
     */
    public function chargeToken(string $reference, float $amount, string $description, string $customerId, string $token): array
    {
        if ($this->isDevMode()) {
            $fails = in_array(round($amount, 2), [11.00, 42.01, 42.76, 42.91], true);
            return ['checkout_id' => 'DEV-' . $reference, 'status' => $fails ? 'FAILED' : 'PAID'];
        }

        $payload = [
            'checkout_reference' => $reference,
            'amount'             => round($amount, 2),
            'currency'           => 'EUR',
            'merchant_code'      => $this->config['merchant_code'] ?? '',
            'description'        => $description,
        ];
        $response = $this->request('POST', '/checkouts', $payload);
        $checkoutId = (string) ($response['id'] ?? '');
        if ($checkoutId === '') {
            $this->logger->error('sumup', 'Unexpected charge-checkout response', ['response' => $response]);
            throw new RuntimeException('Création du prélèvement impossible.');
        }

        $this->request('PUT', '/checkouts/' . rawurlencode($checkoutId), [
            'payment_type' => 'card',
            'token'        => $token,
            'customer_id'  => $customerId,
        ]);

        return ['checkout_id' => $checkoutId, 'status' => null];
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::API_BASE . $path);
        $headers = [
            'Authorization: Bearer ' . ($this->config['api_key'] ?? ''),
            'Accept: application/json',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 20,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            $this->logger->error('sumup', 'Network error', ['error' => $error, 'path' => $path]);
            throw new RuntimeException('SumUp injoignable.');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $decoded = json_decode((string) $raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            $this->logger->error('sumup', 'API error', ['status' => $status, 'body' => is_array($decoded) ? $decoded : (string) $raw]);
            throw new RuntimeException('Erreur SumUp (' . $status . ').');
        }
        return $decoded;
    }
}
