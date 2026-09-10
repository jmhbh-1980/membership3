-- Credit notes (avoirs).
--
-- The remedy for a late residence exception: the member has already paid and
-- already received a numbered full-price invoice, and the club then decides to
-- grant them the Garennois tariff. The difference goes back as a properly
-- numbered avoir referencing the original invoice, not an off-books gesture.
--
-- Deliberately a separate counter table rather than a `series` column added to
-- invoice_counters: that table's one-row-per-invoicing-year primary key is what
-- makes InvoiceNumberService's allocation atomic and gap-free for legal invoice
-- numbers, and reshaping it is not worth the risk. Same allocation algorithm,
-- same 1 August bookkeeping-year boundary, separate sequence.

CREATE TABLE IF NOT EXISTS credit_note_counters (
    season_label VARCHAR(9)   NOT NULL PRIMARY KEY COMMENT 'Aug1-Jul31 invoicing year, e.g. 2026-2027 — distinct from Season',
    last_number  INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at   DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS credit_notes (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id     INT UNSIGNED NOT NULL,
    invoice_id   INT UNSIGNED NOT NULL COMMENT 'the invoice this avoir credits',
    number       VARCHAR(20)  NOT NULL COMMENT 'AV-2026-2027-001',
    season_label VARCHAR(9)   NOT NULL,
    sequence     INT UNSIGNED NOT NULL,
    amount       DECIMAL(8,2) NOT NULL COMMENT 'positive euro amount credited back to the member',
    reason       VARCHAR(500) NOT NULL,
    pdf_path     VARCHAR(255) NOT NULL COMMENT 'relative to uploads/, e.g. invoices/2026-2027/avoir-AV-2026-2027-001-<rand>.pdf',
    issued_by    VARCHAR(190) NOT NULL,
    issued_at    DATETIME     NOT NULL,
    created_at   DATETIME     NOT NULL,
    UNIQUE KEY uq_credit_notes_order (order_id),
    UNIQUE KEY uq_credit_notes_number (number),
    KEY idx_credit_notes_season (season_label),
    CONSTRAINT fk_credit_notes_order FOREIGN KEY (order_id) REFERENCES orders (id),
    CONSTRAINT fk_credit_notes_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
