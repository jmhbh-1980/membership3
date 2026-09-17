-- Passkeys (WebAuthn), admin-only. Enrollment is gated by $adminOnly, so
-- login stays safe purely by construction: nobody but an already-logged-in
-- admin can ever create a row here, and AuthService::login() always resolves
-- role fresh from BJ's live acl_id anyway (never cached), so even a later
-- demotion can't leave a stale passkey granting admin access.

CREATE TABLE IF NOT EXISTS webauthn_credentials (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bj_user_id    INT UNSIGNED NOT NULL,
    credential_id VARCHAR(255) NOT NULL COMMENT 'base64url, as the browser encodes PublicKeyCredential.id',
    public_key    TEXT NOT NULL COMMENT 'PEM-formatted, extracted at registration',
    sign_count    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'anti-clone counter; many platform authenticators (Touch ID, Windows Hello) always report 0',
    label         VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'admin-chosen at registration, e.g. "MacBook Touch ID"',
    created_at    DATETIME NOT NULL,
    last_used_at  DATETIME NULL,
    UNIQUE KEY uq_webauthn_credential_id (credential_id),
    KEY idx_webauthn_bj_user (bj_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
