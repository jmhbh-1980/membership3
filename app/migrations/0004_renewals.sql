-- Renewal support: which subscription each member holds per season (the
-- app-side source of truth once members transact through the app), and
-- subscription-change requests requiring admin approval.

ALTER TABLE orders ADD COLUMN meta TEXT NULL COMMENT 'JSON: kind-specific fulfillment data (renewal subscription, partner, licence removal…)';

CREATE TABLE IF NOT EXISTS member_formulas (
    season_start_year SMALLINT UNSIGNED NOT NULL,
    bj_user_id        INT UNSIGNED NOT NULL,
    subscription_type VARCHAR(20) NOT NULL COMMENT 'heures-pleines | heures-creuses | midi | jeune',
    is_couple         TINYINT(1) NOT NULL DEFAULT 0,
    competitor        TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'this person''s own competitor status, drives licence kind on renewal',
    lessons           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    partner_bj_user_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'other half of a couple registration',
    order_id          INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL,
    PRIMARY KEY (season_start_year, bj_user_id),
    KEY idx_member_formulas_user (bj_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS change_requests (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bj_user_id        INT UNSIGNED NOT NULL,
    email             VARCHAR(190) NOT NULL,
    member_name       VARCHAR(120) NOT NULL,
    current_label     VARCHAR(190) NOT NULL DEFAULT '',
    subscription_type VARCHAR(20) NOT NULL,
    is_couple         TINYINT(1) NOT NULL DEFAULT 0,
    competitor        TINYINT(1) NOT NULL DEFAULT 0,
    lessons           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    partner_email     VARCHAR(190) NOT NULL DEFAULT '',
    season_start_year SMALLINT UNSIGNED NOT NULL,
    status            ENUM('pending','approved','refused','completed') NOT NULL DEFAULT 'pending',
    admin_note        VARCHAR(500) NOT NULL DEFAULT '',
    decided_at        DATETIME NULL,
    created_at        DATETIME NOT NULL,
    KEY idx_change_requests_status (status),
    KEY idx_change_requests_user (bj_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
