<?php

declare(strict_types=1);

/**
 * One-off spike (not part of the app): can the EXISTING hosted-checkout
 * redirect flow also tokenize a card (purpose=SETUP_RECURRING_PAYMENT), or
 * does that require SumUp's client-side Payment Widget instead? Answers
 * the open question in the installments plan before any SumUpService code
 * is written. Uses the SumUp sandbox credentials already in secrets.php —
 * never prints the API key itself, only uses it in the Authorization header.
 *
 *   php app/bin/sumup_tokenize_smoke.php create
 *     -> creates a customer + a SETUP_RECURRING_PAYMENT checkout, prints
 *        the hosted_checkout_url to open and pay with a SumUp test card.
 *   php app/bin/sumup_tokenize_smoke.php check <checkout_id>
 *     -> GETs the checkout, prints status + whether payment_instrument.token
 *        and a mandate came back (i.e. tokenization actually worked).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = require dirname(__DIR__) . '/config/settings.php';
$apiKey = $settings['sumup']['api_key'] ?? '';
$merchantCode = $settings['sumup']['merchant_code'] ?? '';

if ($apiKey === '' || $merchantCode === '') {
    fwrite(STDERR, "Clé API ou merchant_code SumUp absent de secrets.php.\n");
    exit(1);
}

function sumupRequest(string $apiKey, string $method, string $path, ?array $body = null): array
{
    $ch = curl_init('https://api.sumup.com/v0.1' . $path);
    $headers = ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'];
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    echo "HTTP {$status}\n";
    return ['status' => $status, 'body' => json_decode((string) $raw, true)];
}

$command = $argv[1] ?? '';

if ($command === 'create') {
    $customerId = 'smoke-test-' . bin2hex(random_bytes(4));
    echo "Création du client {$customerId}…\n";
    $customerResult = sumupRequest($apiKey, 'POST', '/customers', ['customer_id' => $customerId]);
    print_r($customerResult['body']);

    if ($customerResult['status'] >= 400) {
        fwrite(STDERR, "Échec création client — voir ci-dessus.\n");
        exit(1);
    }

    $reference = 'smoke-' . bin2hex(random_bytes(4));
    echo "\nCréation du checkout de tokenisation (référence {$reference})…\n";
    $checkoutResult = sumupRequest($apiKey, 'POST', '/checkouts', [
        'checkout_reference' => $reference,
        'amount'             => 1.00,
        'currency'           => 'EUR',
        'merchant_code'      => $merchantCode,
        'description'        => 'Smoke test tokenisation',
        'customer_id'        => $customerId,
        'purpose'            => 'SETUP_RECURRING_PAYMENT',
        'return_url'         => 'http://127.0.0.1:8823/paiement/retour/' . $reference,
        'redirect_url'       => 'http://127.0.0.1:8823/paiement/retour/' . $reference,
        'hosted_checkout'    => ['enabled' => true],
    ]);
    print_r($checkoutResult['body']);

    $url = $checkoutResult['body']['hosted_checkout_url'] ?? null;
    $id = $checkoutResult['body']['id'] ?? null;
    if ($url === null || $id === null) {
        fwrite(STDERR, "\nPas d'URL de paiement hébergé renvoyée — la tokenisation via ce chemin échoue dès la création.\n");
        exit(1);
    }
    echo "\n=> Ouvrir cette URL et payer avec une carte de test SumUp :\n{$url}\n";
    echo "=> Puis relancer : php app/bin/sumup_tokenize_smoke.php check {$id}\n";
    exit(0);
}

if ($command === 'check') {
    $checkoutId = $argv[2] ?? '';
    if ($checkoutId === '') {
        fwrite(STDERR, "Usage: check <checkout_id>\n");
        exit(1);
    }
    $result = sumupRequest($apiKey, 'GET', '/checkouts/' . rawurlencode($checkoutId));
    print_r($result['body']);

    $hasToken = isset($result['body']['payment_instrument']['token']);
    $hasMandate = isset($result['body']['mandate']) || isset($result['body']['payment_instrument']['mandate']);
    echo "\nstatus: " . ($result['body']['status'] ?? '?') . "\n";
    echo "payment_instrument.token present: " . ($hasToken ? 'YES' : 'no') . "\n";
    echo "mandate present: " . ($hasMandate ? 'YES' : 'no') . "\n";
    exit(0);
}

fwrite(STDERR, "Usage: sumup_tokenize_smoke.php create|check <checkout_id>\n");
exit(1);
