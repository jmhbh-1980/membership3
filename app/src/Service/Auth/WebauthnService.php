<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Repository\WebauthnCredentialRepository;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use RuntimeException;

/**
 * Passkeys (WebAuthn/FIDO2) for admin login — a faster alternative to the
 * magic-link email round-trip, not a replacement: enrollment (see
 * AdminPasskeyController) requires already being logged in via magic link,
 * so a lost device or new admin always has a way back in.
 *
 * 'none' attestation only (see lbuchs/webauthn's README): this only needs to
 * confirm "same device as registration", not verify authenticator make/model
 * against a root CA — there's no fleet of club-owned hardware to check
 * against, so requesting more than that would just be a bigger prompt for
 * admins with no real security gain here.
 *
 * $rpId (the domain WebAuthn credentials are scoped to) has to be resolved
 * per-request, not fixed at construction, since the same code runs on
 * members.bad-squash.org in production and on localhost in dev — a
 * credential registered under one origin is simply invisible under another,
 * by design, so this can never be hardcoded.
 */
final class WebauthnService
{
    public function __construct(
        private readonly WebauthnCredentialRepository $credentials,
        private readonly string $rpName,
    ) {
    }

    /** @return array{options: array, challenge: string} */
    public function registrationOptions(string $rpId, int $bjUserId, string $email, string $displayName): array
    {
        $webAuthn = $this->webAuthn($rpId);
        $exclude = array_map(
            static fn (array $c) => ByteBuffer::fromBase64Url($c['credential_id']),
            $this->credentials->forUser($bjUserId),
        );

        $args = $webAuthn->getCreateArgs(
            (string) $bjUserId,
            $email,
            $displayName,
            60,
            true,  // requireResidentKey — a discoverable credential is what makes it a "passkey"
            true,  // requireUserVerification — biometric/PIN, not just a tap
            null,
            $exclude,
        );

        return [
            'options'   => json_decode(json_encode($args), true),
            'challenge' => $webAuthn->getChallenge()->jsonSerialize(),
        ];
    }

    /**
     * @param array{clientDataJSON: string, attestationObject: string} $response base64url fields from the browser
     * @throws RuntimeException on a failed ceremony (bad challenge, wrong origin, forged signature…)
     */
    public function verifyRegistration(string $rpId, array $response, string $challenge, int $bjUserId, string $label): void
    {
        $webAuthn = $this->webAuthn($rpId);

        try {
            $data = $webAuthn->processCreate(
                ByteBuffer::fromBase64Url($response['clientDataJSON'])->getBinaryString(),
                ByteBuffer::fromBase64Url($response['attestationObject'])->getBinaryString(),
                ByteBuffer::fromBase64Url($challenge),
                true,
            );
        } catch (WebAuthnException $e) {
            throw new RuntimeException('Échec de l\'enregistrement de la clé d\'accès : ' . $e->getMessage(), 0, $e);
        }

        $this->credentials->create(
            $bjUserId,
            (new ByteBuffer($data->credentialId))->jsonSerialize(),
            $data->credentialPublicKey,
            $data->signatureCounter ?? 0,
            $label,
        );
    }

    /** @return array{options: array, challenge: string} */
    public function loginOptions(string $rpId): array
    {
        $webAuthn = $this->webAuthn($rpId);
        // No allowCredentials list: a passkey is discoverable, so the browser
        // finds its own matching credential for this rpId without being told
        // which ids to look for — that's what lets login start with no
        // email typed in first.
        $args = $webAuthn->getGetArgs([], 60, requireUserVerification: true);

        return [
            'options'   => json_decode(json_encode($args), true),
            'challenge' => $webAuthn->getChallenge()->jsonSerialize(),
        ];
    }

    /**
     * @param array{id: string, clientDataJSON: string, authenticatorData: string, signature: string} $response base64url fields from the browser
     * @return ?int the bj_user_id on success, null on any failure (bad challenge, unknown credential, forged signature…) — deliberately not an exception, since a failed login attempt isn't a bug
     */
    public function verifyLogin(string $rpId, array $response, string $challenge): ?int
    {
        $credentialId = $response['id'] ?? '';
        $stored = $credentialId !== '' ? $this->credentials->findByCredentialId($credentialId) : null;
        if ($stored === null) {
            return null;
        }

        $webAuthn = $this->webAuthn($rpId);

        try {
            $webAuthn->processGet(
                ByteBuffer::fromBase64Url($response['clientDataJSON'])->getBinaryString(),
                ByteBuffer::fromBase64Url($response['authenticatorData'])->getBinaryString(),
                ByteBuffer::fromBase64Url($response['signature'])->getBinaryString(),
                $stored['public_key'],
                ByteBuffer::fromBase64Url($challenge),
                (int) $stored['sign_count'],
                true,
            );
        } catch (WebAuthnException) {
            return null;
        }

        $this->credentials->updateAfterLogin((int) $stored['id'], $webAuthn->getSignatureCounter() ?? (int) $stored['sign_count']);

        return (int) $stored['bj_user_id'];
    }

    private function webAuthn(string $rpId): WebAuthn
    {
        return new WebAuthn($this->rpName, $rpId, ['none'], true);
    }
}
