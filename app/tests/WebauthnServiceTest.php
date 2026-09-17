<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\WebauthnCredentialRepository;
use App\Service\Auth\WebauthnService;
use App\Support\Db;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Integration test against the dev MySQL (bj_user_id far outside any real
 * range, cleaned up in tearDown — same convention as ResidenceExceptionRepositoryTest).
 *
 * There is no way to drive a real navigator.credentials.create()/get() from
 * PHPUnit — that needs an actual authenticator (Touch ID, a security key…).
 * Instead this hand-builds exactly what a real browser+authenticator would
 * send: a real EC P-256 keypair (openssl), a hand-encoded CBOR COSE key and
 * attestationObject ('none' format, so no attestation signature is needed —
 * see WebauthnService's docblock for why 'none' is all this app uses), and a
 * real ECDSA-SHA256 signature over authData+clientDataHash for the login
 * half. Every byte layout here was independently verified against the
 * library's own decoder before being folded into this test — see the
 * WebauthnService PR for the scratch verification. This proves the actual
 * registration→login round trip works, not just that garbage is rejected.
 */
final class WebauthnServiceTest extends TestCase
{
    private const int BJ_USER_ID = 999999201;
    private const string RP_ID = 'localhost';
    private const string ORIGIN = 'http://localhost';

    private Db $db;
    private WebauthnCredentialRepository $credentials;
    private WebauthnService $service;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->credentials = new WebauthnCredentialRepository($this->db);
        $this->service = new WebauthnService($this->credentials, 'Test Club');
    }

    protected function tearDown(): void
    {
        $this->db->pdo()->prepare('DELETE FROM webauthn_credentials WHERE bj_user_id = ?')->execute([self::BJ_USER_ID]);
    }

    public function testFullRegistrationThenLoginRoundTrip(): void
    {
        [$keyPair, $credentialId] = $this->registerAPasskey();

        $rows = $this->credentials->forUser(self::BJ_USER_ID);
        self::assertCount(1, $rows);
        self::assertSame('MacBook Touch ID', $rows[0]['label']);
        self::assertSame(0, (int) $rows[0]['sign_count']);
        self::assertNull($rows[0]['last_used_at']);

        // --- Login ---
        ['options' => $getOptions, 'challenge' => $loginChallenge] = $this->service->loginOptions(self::RP_ID);
        self::assertSame([], $getOptions['publicKey']['allowCredentials'] ?? []);

        $authData = $this->authenticatorData(userPresent: true, userVerified: true, attested: null, signCount: 0);
        $clientDataJSON = $this->clientDataJson('webauthn.get', $loginChallenge);
        $signature = $this->sign($keyPair, $authData . hash('sha256', $clientDataJSON, true));

        $bjUserId = $this->service->verifyLogin(self::RP_ID, [
            'id'                => $credentialId,
            'clientDataJSON'    => $this->b64url($clientDataJSON),
            'authenticatorData' => $this->b64url($authData),
            'signature'         => $this->b64url($signature),
        ], $loginChallenge);

        self::assertSame(self::BJ_USER_ID, $bjUserId);

        $updated = $this->credentials->findByCredentialId($credentialId);
        self::assertNotNull($updated['last_used_at']);
    }

    public function testVerifyLoginFailsOnTamperedSignature(): void
    {
        [$keyPair, $credentialId] = $this->registerAPasskey();

        ['challenge' => $loginChallenge] = $this->service->loginOptions(self::RP_ID);
        $authData = $this->authenticatorData(userPresent: true, userVerified: true, attested: null, signCount: 0);
        $clientDataJSON = $this->clientDataJson('webauthn.get', $loginChallenge);
        $signature = $this->sign($keyPair, $authData . hash('sha256', $clientDataJSON, true));
        $signature[0] = $signature[0] === "\x00" ? "\x01" : "\x00"; // flip a byte

        $bjUserId = $this->service->verifyLogin(self::RP_ID, [
            'id'                => $credentialId,
            'clientDataJSON'    => $this->b64url($clientDataJSON),
            'authenticatorData' => $this->b64url($authData),
            'signature'         => $this->b64url($signature),
        ], $loginChallenge);

        self::assertNull($bjUserId);
    }

    public function testVerifyLoginFailsOnUnknownCredential(): void
    {
        $bjUserId = $this->service->verifyLogin(self::RP_ID, [
            'id'                => 'does-not-exist',
            'clientDataJSON'    => 'x',
            'authenticatorData' => 'x',
            'signature'         => 'x',
        ], 'irrelevant-challenge');

        self::assertNull($bjUserId);
    }

    public function testVerifyLoginFailsOnWrongChallenge(): void
    {
        [$keyPair, $credentialId] = $this->registerAPasskey();

        ['challenge' => $loginChallenge] = $this->service->loginOptions(self::RP_ID);
        $authData = $this->authenticatorData(userPresent: true, userVerified: true, attested: null, signCount: 0);
        // Signed against the real challenge, but verifyLogin() is told a different one.
        $clientDataJSON = $this->clientDataJson('webauthn.get', $loginChallenge);
        $signature = $this->sign($keyPair, $authData . hash('sha256', $clientDataJSON, true));

        $bjUserId = $this->service->verifyLogin(self::RP_ID, [
            'id'                => $credentialId,
            'clientDataJSON'    => $this->b64url($clientDataJSON),
            'authenticatorData' => $this->b64url($authData),
            'signature'         => $this->b64url($signature),
        ], (new ByteBuffer('a-completely-different-challenge'))->jsonSerialize());

        self::assertNull($bjUserId);
    }

    public function testVerifyRegistrationRejectsAMismatchedChallenge(): void
    {
        $keyPair = $this->generateKeyPair();
        $credentialId = random_bytes(16);
        $authData = $this->authenticatorData(true, true, ['keyPair' => $keyPair, 'credentialId' => $credentialId], 0);
        $attestationObject = $this->attestationObject($authData);

        ['options' => $createOptions] = $this->service->registrationOptions(self::RP_ID, self::BJ_USER_ID, 'admin@example.com', 'Admin');
        $clientDataJSON = $this->clientDataJson('webauthn.create', $createOptions['publicKey']['challenge']);

        $this->expectException(RuntimeException::class);
        $this->service->verifyRegistration(self::RP_ID, [
            'clientDataJSON'    => $this->b64url($clientDataJSON),
            'attestationObject' => $this->b64url($attestationObject),
        ], (new ByteBuffer('not-the-real-challenge'))->jsonSerialize(), self::BJ_USER_ID, 'x');
    }

    public function testRegistrationOptionsExcludesAlreadyRegisteredCredentials(): void
    {
        [, $credentialId] = $this->registerAPasskey();

        ['options' => $options] = $this->service->registrationOptions(self::RP_ID, self::BJ_USER_ID, 'admin@example.com', 'Admin');

        $excludedIds = array_column($options['publicKey']['excludeCredentials'], 'id');
        self::assertContains($credentialId, $excludedIds);
    }

    /** @return array{0: array, 1: string} the OpenSSL key pair resource-array and the stored credential's base64url id */
    private function registerAPasskey(): array
    {
        $keyPair = $this->generateKeyPair();
        $credentialId = random_bytes(16);
        $authData = $this->authenticatorData(true, true, ['keyPair' => $keyPair, 'credentialId' => $credentialId], 0);
        $attestationObject = $this->attestationObject($authData);

        ['options' => $createOptions, 'challenge' => $challenge] = $this->service->registrationOptions(
            self::RP_ID,
            self::BJ_USER_ID,
            'admin@example.com',
            'Admin',
        );
        self::assertSame($challenge, $createOptions['publicKey']['challenge']);

        $clientDataJSON = $this->clientDataJson('webauthn.create', $challenge);

        $this->service->verifyRegistration(self::RP_ID, [
            'clientDataJSON'    => $this->b64url($clientDataJSON),
            'attestationObject' => $this->b64url($attestationObject),
        ], $challenge, self::BJ_USER_ID, 'MacBook Touch ID');

        return [$keyPair, (new ByteBuffer($credentialId))->jsonSerialize()];
    }

    private function generateKeyPair(): array
    {
        $resource = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($resource, 'EC key generation failed: ' . openssl_error_string());
        return ['resource' => $resource, 'details' => openssl_pkey_get_details($resource)];
    }

    private function sign(array $keyPair, string $data): string
    {
        $ok = openssl_sign($data, $signature, $keyPair['resource'], OPENSSL_ALGO_SHA256);
        self::assertTrue($ok, 'openssl_sign failed: ' . openssl_error_string());
        return $signature;
    }

    /** @param ?array{keyPair: array, credentialId: string} $attested null for a login (no attested credential data) */
    private function authenticatorData(bool $userPresent, bool $userVerified, ?array $attested, int $signCount): string
    {
        $rpIdHash = hash('sha256', self::RP_ID, true);
        $flagsByte = ($userPresent ? 0x01 : 0) | ($userVerified ? 0x04 : 0) | ($attested !== null ? 0x40 : 0);
        $base = $rpIdHash . chr($flagsByte) . pack('N', $signCount);

        if ($attested === null) {
            return $base;
        }

        $x = $attested['keyPair']['details']['ec']['x'];
        $y = $attested['keyPair']['details']['ec']['y'];
        $cose = $this->cborMap(5)
            . $this->cborUint(1) . $this->cborUint(2)       // kty: EC2
            . $this->cborUint(3) . $this->cborNegInt(-7)    // alg: ES256
            . $this->cborNegInt(-1) . $this->cborUint(1)    // crv: P-256
            . $this->cborNegInt(-2) . $this->cborBytes($x)
            . $this->cborNegInt(-3) . $this->cborBytes($y);

        $aaguid = str_repeat("\x00", 16);
        return $base . $aaguid . pack('n', strlen($attested['credentialId'])) . $attested['credentialId'] . $cose;
    }

    private function attestationObject(string $authData): string
    {
        return $this->cborMap(3)
            . $this->cborText('fmt') . $this->cborText('none')
            . $this->cborText('attStmt') . "\xA0" // empty map
            . $this->cborText('authData') . $this->cborBytes($authData);
    }

    private function clientDataJson(string $type, string $challengeB64Url): string
    {
        return json_encode([
            'type'        => $type,
            'challenge'   => $challengeB64Url,
            'origin'      => self::ORIGIN,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR);
    }

    private function b64url(string $binary): string
    {
        return (new ByteBuffer($binary))->jsonSerialize();
    }

    // --- Minimal CBOR encoding: only the definite-length shapes this fixture needs. ---

    private function cborUint(int $v): string
    {
        return $v < 24 ? chr($v) : chr(0x18) . chr($v);
    }

    private function cborNegInt(int $v): string
    {
        $n = -1 - $v;
        return $n < 24 ? chr(0x20 | $n) : chr(0x20 | 24) . chr($n);
    }

    private function cborBytes(string $s): string
    {
        $len = strlen($s);
        return match (true) {
            $len < 24 => chr(0x40 | $len) . $s,
            $len < 256 => chr(0x40 | 24) . chr($len) . $s,
            default => chr(0x40 | 25) . pack('n', $len) . $s,
        };
    }

    private function cborText(string $s): string
    {
        $len = strlen($s);
        return $len < 24 ? chr(0x60 | $len) . $s : chr(0x60 | 24) . chr($len) . $s;
    }

    private function cborMap(int $pairs): string
    {
        return chr(0xA0 | $pairs);
    }
}
