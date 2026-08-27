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
     *
     * @return array{status: string, transactionCode: ?string}
     */
    public function checkoutStatus(array $order): array
    {
        if ($this->isDevMode()) {
            return [
                'status'          => $order['dev_paid'] ? 'PAID' : 'PENDING',
                'transactionCode' => null,
                'url'             => $order['dev_paid'] ? null : '/paiement/dev/' . $order['checkout_reference'],
            ];
        }
        $response = $this->request('GET', '/checkouts/' . rawurlencode($order['checkout_id']));
        return [
            'status'          => strtoupper((string) ($response['status'] ?? 'PENDING')),
            'transactionCode' => $response['transaction_code'] ?? $response['transactions'][0]['transaction_code'] ?? null,
            'url'             => $response['hosted_checkout_url'] ?? null,
        ];
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
