-- Orders/payments and group-lesson enrollments.

CREATE TABLE IF NOT EXISTS orders (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind               ENUM('join','renewal','credits','change') NOT NULL,
    application_id     INT UNSIGNED NULL,
    bj_user_id         INT UNSIGNED NOT NULL DEFAULT 0,
    email              VARCHAR(190) NOT NULL,
    amount             DECIMAL(8,2) NOT NULL,
    currency           CHAR(3) NOT NULL DEFAULT 'EUR',
    cart_lines         TEXT NOT NULL COMMENT 'JSON-encoded cart lines',
    status             ENUM('pending','paid','fulfilling','fulfilled','failed','canceled') NOT NULL DEFAULT 'pending',
    checkout_id        VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'SumUp checkout id',
    checkout_reference CHAR(36) NOT NULL COMMENT 'our reference sent to SumUp',
    dev_paid           TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'dev-mode payment simulator marker',
    fulfilled_at       DATETIME NULL,
    created_at         DATETIME NOT NULL,
    updated_at         DATETIME NOT NULL,
    UNIQUE KEY uq_orders_reference (checkout_reference),
    KEY idx_orders_application (application_id),
    KEY idx_orders_status (status),
    KEY idx_orders_bj_user (bj_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lesson_enrollments (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    season_start_year SMALLINT UNSIGNED NOT NULL,
    bj_user_id        INT UNSIGNED NOT NULL DEFAULT 0,
    firstname         VARCHAR(50) NOT NULL,
    lastname          VARCHAR(50) NOT NULL,
    email             VARCHAR(190) NOT NULL DEFAULT '',
    order_id          INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL,
    KEY idx_lessons_season (season_start_year),
    KEY idx_lessons_bj_user (bj_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
