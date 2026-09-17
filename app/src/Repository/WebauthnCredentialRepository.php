<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;

/**
 * Admin-only passkeys (WebAuthn credentials) — see migration 0025 for why
 * login is safe by construction without an extra role check here.
 */
class WebauthnCredentialRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array[] newest first */
    public function forUser(int $bjUserId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM webauthn_credentials WHERE bj_user_id = ? ORDER BY created_at DESC',
        );
        $stmt->execute([$bjUserId]);
        return $stmt->fetchAll();
    }

    public function findByCredentialId(string $credentialId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM webauthn_credentials WHERE credential_id = ?');
        $stmt->execute([$credentialId]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM webauthn_credentials WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function create(int $bjUserId, string $credentialId, string $publicKey, int $signCount, string $label): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO webauthn_credentials (bj_user_id, credential_id, public_key, sign_count, label, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
        );
        $stmt->execute([$bjUserId, $credentialId, $publicKey, $signCount, mb_substr($label, 0, 100)]);
    }

    public function updateAfterLogin(int $id, int $signCount): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE webauthn_credentials SET sign_count = ?, last_used_at = NOW() WHERE id = ?',
        );
        $stmt->execute([$signCount, $id]);
    }

    /** Scoped to the owner — a passkey can only ever be deleted by the admin it belongs to. */
    public function delete(int $id, int $bjUserId): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM webauthn_credentials WHERE id = ? AND bj_user_id = ?');
        $stmt->execute([$id, $bjUserId]);
    }
}
