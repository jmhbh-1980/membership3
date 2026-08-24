<?php

declare(strict_types=1);

namespace App\Service\BalleJaune;

use App\Support\Logger;

/**
 * Thin client for the Balle Jaune REST API (see ballejauneapi.md).
 *
 * Every response uses the same envelope: {"success": bool, "data": {...}} or
 * {"success": false, "error": {"code", "message"}} — this client unwraps
 * `data` so callers never touch the envelope. On 429 it honours Retry-After
 * once (capped) before failing.
 */
class BalleJauneClient
{
    private const int MAX_RETRY_AFTER_SECONDS = 10;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly Logger $logger,
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    /** @return array the unwrapped `data` object */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, [], $body);
    }

    public function patch(string $path, array $body): array
    {
        return $this->request('PATCH', $path, [], $body);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    private function request(string $method, string $path, array $query = [], ?array $body = null, bool $isRetry = false): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = ['X-API-Key: ' . $this->apiKey, 'Accept: application/json'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_HEADER         => true,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            $this->logger->error('ballejaune', 'Network error', ['method' => $method, 'path' => $path, 'error' => $error]);
            throw new BalleJauneException("Balle Jaune injoignable : {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);

        if ($status === 429 && !$isRetry) {
            $retryAfter = $this->retryAfterSeconds($rawHeaders);
            $this->logger->info('ballejaune', 'Rate limited, retrying', ['after' => $retryAfter, 'path' => $path]);
            sleep($retryAfter);
            return $this->request($method, $path, $query, $body, isRetry: true);
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            $this->logger->error('ballejaune', 'Invalid JSON response', ['status' => $status, 'path' => $path]);
            throw new BalleJauneException('Réponse Balle Jaune invalide.', $status);
        }

        if (($decoded['success'] ?? false) !== true) {
            $apiCode = $decoded['error']['code'] ?? null;
            $message = $decoded['error']['message'] ?? 'Erreur inconnue';
            $this->logger->error('ballejaune', 'API error', [
                'status' => $status, 'code' => $apiCode, 'message' => $message, 'method' => $method, 'path' => $path,
            ]);
            throw new BalleJauneException("Erreur Balle Jaune : {$message}", $status, is_int($apiCode) ? $apiCode : null);
        }

        return $decoded['data'] ?? [];
    }

    private function retryAfterSeconds(string $rawHeaders): int
    {
        if (preg_match('/^Retry-After:\s*(\d+)/mi', $rawHeaders, $m)) {
            return min((int) $m[1], self::MAX_RETRY_AFTER_SECONDS);
        }
        return 2;
    }
}
