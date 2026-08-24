-- Core tables: settings, magic-link tokens, audit trail, email log.
-- MySQL 5.7 / InnoDB / utf8mb4.

CREATE TABLE IF NOT EXISTS settings (
    name       VARCHAR(100) NOT NULL PRIMARY KEY,
    value      TEXT NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS magic_tokens (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(190) NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    bj_user_id  INT UNSIGNED NOT NULL,
    purpose     VARCHAR(30) NOT NULL DEFAULT 'login',
    created_ip  VARCHAR(45) NOT NULL DEFAULT '',
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL,
    UNIQUE KEY uq_magic_tokens_hash (token_hash),
    KEY idx_magic_tokens_email (email),
    KEY idx_magic_tokens_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor      VARCHAR(190) NOT NULL COMMENT 'email or "system"',
    action     VARCHAR(100) NOT NULL,
    entity     VARCHAR(50)  NOT NULL DEFAULT '',
    entity_id  VARCHAR(50)  NOT NULL DEFAULT '',
    details    TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_audit_entity (entity, entity_id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_log (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recipient  VARCHAR(190) NOT NULL,
    subject    VARCHAR(255) NOT NULL,
    template   VARCHAR(100) NOT NULL DEFAULT '',
    status     ENUM('sent','failed') NOT NULL,
    error      TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_email_recipient (recipient),
    KEY idx_email_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
