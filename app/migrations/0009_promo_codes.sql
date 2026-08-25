-- Admin-issued promo codes: a percentage or fixed-euro discount applied at
-- join/renewal checkout, for one-off pricing edge cases (a board-approved
-- discount, a fee waiver) that would otherwise need a manual workaround or a
-- one-off pricing.<season>.php edit. Usage is never counted with a mutable
-- counter — always computed live via COUNT(*) on orders.promo_code_id, same
-- as every other admin dashboard badge (AdminController::counts()).

CREATE TABLE IF NOT EXISTS promo_codes (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code          VARCHAR(32) NOT NULL COMMENT 'normalized uppercase',
    kind          ENUM('percent','fixed') NOT NULL,
    value         DECIMAL(8,2) NOT NULL COMMENT 'percent (0-100) or euros off the total',
    scope         ENUM('join','renewal','both') NOT NULL DEFAULT 'both',
    max_uses      INT UNSIGNED NULL COMMENT 'null = unlimited',
    expires_at    DATETIME NULL,
    active        TINYINT(1) NOT NULL DEFAULT 1,
    note          VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'admin memo, e.g. reason/approval',
    created_by    VARCHAR(190) NOT NULL,
    created_at    DATETIME NOT NULL,
    UNIQUE KEY uq_promo_codes_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders
    ADD COLUMN promo_code_id INT UNSIGNED NULL AFTER meta,
    ADD COLUMN discount_amount DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER promo_code_id,
    ADD KEY idx_orders_promo_code (promo_code_id);

ALTER TABLE applications
    ADD COLUMN promo_code VARCHAR(32) NOT NULL DEFAULT '';
