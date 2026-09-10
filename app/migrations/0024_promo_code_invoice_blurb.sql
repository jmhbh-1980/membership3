-- Optional per-code text printed under a promo discount line on the invoice.
--
-- Deliberately NOT the existing `note` column, which is an internal admin memo
-- ("reason/approval" per its own comment) and already holds things nobody
-- intends a member to read. Reusing it would have turned every note ever typed
-- into member-facing text the moment this shipped, retroactively.
--
-- Empty is the normal case: a discount line then prints its label alone,
-- exactly as before this column existed.

ALTER TABLE promo_codes
    ADD COLUMN invoice_blurb VARCHAR(300) NOT NULL DEFAULT ''
        COMMENT 'optional text printed under this code''s discount line on the invoice — member-facing, unlike note'
        AFTER note;
