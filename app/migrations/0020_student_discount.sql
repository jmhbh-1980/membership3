ALTER TABLE applications
    ADD COLUMN student_discount_requested      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'applicant checked "je suis etudiant(e)" and uploaded a certificate',
    ADD COLUMN student_discount_approved       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'admin approved the uploaded certificate',
    ADD COLUMN student_discount_refused_at     DATETIME NULL DEFAULT NULL,
    ADD COLUMN student_discount_refusal_reason VARCHAR(500) NOT NULL DEFAULT '';

ALTER TABLE documents
    MODIFY COLUMN kind ENUM('photo','justificatif','medical_certificate','student_certificate') NOT NULL;

ALTER TABLE orders
    MODIFY COLUMN status ENUM('pending','paid','fulfilling','fulfilled','failed','canceled','refunded','processed','awaiting_promo_approval','awaiting_bank_transfer','awaiting_student_approval') NOT NULL DEFAULT 'pending',
    ADD COLUMN student_discount TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'true when the discount came from student status rather than a promo code' AFTER discount_amount;

CREATE TABLE IF NOT EXISTS renewal_student_certificates (
    season_start_year SMALLINT UNSIGNED NOT NULL,
    bj_user_id         INT UNSIGNED NOT NULL,
    status              ENUM('pending','approved','refused') NOT NULL DEFAULT 'pending',
    original_name       VARCHAR(255) NOT NULL DEFAULT '',
    stored_name         VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'under uploads/renewals/{season}/{bj_user_id}/',
    mime                VARCHAR(100) NOT NULL DEFAULT '',
    size                INT UNSIGNED NOT NULL DEFAULT 0,
    requested_at        DATETIME NOT NULL,
    decided_at          DATETIME NULL DEFAULT NULL,
    decided_by          VARCHAR(190) NOT NULL DEFAULT '',
    refusal_reason      VARCHAR(500) NOT NULL DEFAULT '',
    PRIMARY KEY (season_start_year, bj_user_id),
    KEY idx_renewal_student_certificates_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
