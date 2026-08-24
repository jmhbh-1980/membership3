-- Annual renewal compliance for members still under 18: health questionnaire
-- outcome (renewals have no application_id to hang the existing
-- attestations table off, hence a dedicated table here). Guardian contact
-- itself lives in Balle Jaune (custom1), not here.

CREATE TABLE IF NOT EXISTS renewal_attestations (
    season_start_year SMALLINT UNSIGNED NOT NULL,
    bj_user_id         INT UNSIGNED NOT NULL,
    outcome             ENUM('all_negative','certificate') NOT NULL,
    guardian_fullname   VARCHAR(100) NOT NULL DEFAULT '',
    signature_ip        VARCHAR(45) NOT NULL DEFAULT '',
    signed_at           DATETIME NULL,
    document_stored_name VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'generated attestation PDF, or an uploaded certificate',
    created_at          DATETIME NOT NULL,
    PRIMARY KEY (season_start_year, bj_user_id),
    KEY idx_renewal_attestations_user (bj_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
