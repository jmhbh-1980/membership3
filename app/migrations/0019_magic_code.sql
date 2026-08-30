ALTER TABLE magic_tokens
    ADD COLUMN code_hash CHAR(64) NULL AFTER token_hash,
    ADD COLUMN code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER code_hash;
