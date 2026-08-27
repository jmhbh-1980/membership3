-- Formal numbered invoices for join/renewal orders only (credits/change never
-- get one). Numbering resets on 1 August each year — a full month before the
-- club's actual Season boundary (1 Sept) — per the treasurer's own
-- bookkeeping-year convention; see InvoiceNumberService, which owns this
-- rule and must NOT reuse Season::current()/label().

CREATE TABLE IF NOT EXISTS invoice_counters (
    season_label VARCHAR(9)   NOT NULL PRIMARY KEY COMMENT 'Aug1-Jul31 invoicing year, e.g. 2026-2027 — distinct from Season',
    last_number  INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at   DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id     INT UNSIGNED NOT NULL,
    number       VARCHAR(20)  NOT NULL COMMENT 'SQ-2026-2027-001',
    season_label VARCHAR(9)   NOT NULL,
    sequence     INT UNSIGNED NOT NULL,
    amount       DECIMAL(8,2) NOT NULL COMMENT 'snapshot of orders.amount at issuance',
    pdf_path     VARCHAR(255) NOT NULL COMMENT 'relative to uploads/, e.g. invoices/2026-2027/facture-SQ-2026-2027-001-<rand>.pdf',
    issued_at    DATETIME     NOT NULL,
    created_at   DATETIME     NOT NULL,
    UNIQUE KEY uq_invoices_order (order_id),
    UNIQUE KEY uq_invoices_number (number),
    KEY idx_invoices_season (season_label),
    CONSTRAINT fk_invoices_order FOREIGN KEY (order_id) REFERENCES orders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
