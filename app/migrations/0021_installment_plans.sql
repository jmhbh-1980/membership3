CREATE TABLE IF NOT EXISTS installment_plans (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bj_user_id           INT UNSIGNED NOT NULL,
    season_start_year    SMALLINT UNSIGNED NOT NULL,
    installment_count    TINYINT UNSIGNED NOT NULL,
    renewal_intent       TEXT NOT NULL COMMENT 'frozen renewal_intent JSON, since cron charges run long after the session is gone',
    schedule             TEXT NOT NULL COMMENT 'JSON array of remaining installments: [{number, amount, due_date, extends_to, status}], status pending|charged|failed',
    sumup_customer_id    VARCHAR(64) NOT NULL,
    sumup_payment_token  VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'set once installment 1 is paid and the card is tokenized',
    status               ENUM('active','completed','lapsed','canceled') NOT NULL DEFAULT 'active',
    created_at           DATETIME NOT NULL,
    updated_at           DATETIME NOT NULL,
    KEY idx_installment_plans_bj_user (bj_user_id),
    KEY idx_installment_plans_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders
    ADD COLUMN installment_plan_id INT UNSIGNED NULL AFTER meta,
    ADD COLUMN installment_number  TINYINT UNSIGNED NULL AFTER installment_plan_id,
    ADD KEY idx_orders_installment_plan (installment_plan_id);
