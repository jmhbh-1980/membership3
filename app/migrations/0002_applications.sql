-- Prospect applications (join requests) and their documents/attestations.

CREATE TABLE IF NOT EXISTS applications (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token         CHAR(64) NOT NULL COMMENT 'resumable-link token (also used pre-payment)',
    status        ENUM('draft','submitted','validated','awaiting_payment','paid','fulfilled','rejected') NOT NULL DEFAULT 'draft',
    email         VARCHAR(190) NOT NULL COMMENT 'contact email of the applicant (guardian for minors)',
    season_start_year SMALLINT UNSIGNED NOT NULL,
    subscription_type VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'heures-pleines | heures-creuses | midi | jeune',
    is_couple     TINYINT(1) NOT NULL DEFAULT 0,
    residence     VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'garennois | hors-commune',
    lessons_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    midi_residency_override        TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'admin-granted exception: Midi at Garennois price for a Hors-commune applicant',
    midi_residency_override_reason VARCHAR(500) NOT NULL DEFAULT '',
    rejection_reason       VARCHAR(500) NOT NULL DEFAULT '',
    submitted_at  DATETIME NULL,
    validated_at  DATETIME NULL,
    created_at    DATETIME NOT NULL,
    updated_at    DATETIME NOT NULL,
    UNIQUE KEY uq_applications_token (token),
    KEY idx_applications_status (status),
    KEY idx_applications_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS application_people (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_id INT UNSIGNED NOT NULL,
    position       TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 = applicant, 2 = couple partner',
    firstname      VARCHAR(50) NOT NULL,
    lastname       VARCHAR(50) NOT NULL,
    sex            CHAR(1) NOT NULL DEFAULT '' COMMENT 'M | W | empty',
    birthdate      DATE NOT NULL,
    email          VARCHAR(190) NOT NULL DEFAULT '',
    phone          VARCHAR(40) NOT NULL DEFAULT '',
    address        VARCHAR(255) NOT NULL DEFAULT '',
    postalcode     VARCHAR(10) NOT NULL DEFAULT '',
    city           VARCHAR(100) NOT NULL DEFAULT '',
    is_minor       TINYINT(1) NOT NULL DEFAULT 0,
    guardian_fullname VARCHAR(100) NOT NULL DEFAULT '',
    guardian_email    VARCHAR(190) NOT NULL DEFAULT '',
    guardian_phone    VARCHAR(40) NOT NULL DEFAULT '',
    competitor     TINYINT(1) NOT NULL DEFAULT 0,
    licence_removed        TINYINT(1) NOT NULL DEFAULT 0,
    licence_removal_reason VARCHAR(500) NOT NULL DEFAULT '',
    bj_user_id     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'filled at fulfillment',
    created_at     DATETIME NOT NULL,
    UNIQUE KEY uq_people_app_position (application_id, position),
    KEY idx_people_application (application_id),
    CONSTRAINT fk_people_application FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS documents (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_id INT UNSIGNED NOT NULL,
    person_position TINYINT UNSIGNED NOT NULL DEFAULT 1,
    kind           ENUM('photo','justificatif','medical_certificate') NOT NULL,
    original_name  VARCHAR(255) NOT NULL,
    stored_name    VARCHAR(100) NOT NULL COMMENT 'random name under uploads/applications/{id}/',
    mime           VARCHAR(100) NOT NULL,
    size           INT UNSIGNED NOT NULL,
    created_at     DATETIME NOT NULL,
    KEY idx_documents_application (application_id),
    CONSTRAINT fk_documents_application FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attestations (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_id INT UNSIGNED NOT NULL,
    person_position TINYINT UNSIGNED NOT NULL DEFAULT 1,
    outcome        ENUM('all_negative','certificate') NOT NULL,
    guardian_fullname VARCHAR(100) NOT NULL DEFAULT '',
    signature_ip   VARCHAR(45) NOT NULL DEFAULT '',
    signed_at      DATETIME NULL,
    pdf_stored_name VARCHAR(100) NOT NULL DEFAULT '',
    created_at     DATETIME NOT NULL,
    UNIQUE KEY uq_attestations_app_position (application_id, person_position),
    CONSTRAINT fk_attestations_application FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
