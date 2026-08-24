-- Renewal licence-waiver requests reuse the change_requests queue: a member
-- asking to waive their mandatory licence now needs admin approval, same as
-- a subscription/couple-status change already does. `kind` distinguishes the
-- two; a 'licence' row still fills subscription_type/is_couple/competitor/
-- lessons with the member's current (unchanged) values so the existing
-- approved-request seeding logic in RenewalController::context() works
-- unmodified for both kinds.

ALTER TABLE change_requests
    ADD COLUMN kind ENUM('formula','licence') NOT NULL DEFAULT 'formula' AFTER id,
    ADD COLUMN licence_removed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN licence_removal_reason VARCHAR(500) NOT NULL DEFAULT '',
    ADD COLUMN partner_licence_removed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN partner_licence_removal_reason VARCHAR(500) NOT NULL DEFAULT '';
