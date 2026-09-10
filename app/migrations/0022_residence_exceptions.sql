-- Residence pricing exceptions.
--
-- Splits "where the member actually lives" (residence, derived from the
-- postcode by PricingService::residenceForZip(), never editable) from "which
-- price grid they are read at" (pricing_residence). An admin can grant the
-- Garennois tariff to a non-resident on an exceptional basis — and the reverse
-- is possible too, for a 92250 address that does not hold up.
--
-- This generalises applications.midi_residency_override, which did exactly
-- this for the Midi formula alone: Midi is the only subscription with no
-- hors-commune price bucket, so overriding the grid both unlocks it and prices
-- it at the Garennois rate. Every other formula simply swaps price bucket.
--
-- Scope is deliberately ONE SEASON: an exception must be re-granted every
-- year, so a favour never quietly becomes a permanent right. That is the
-- lesson of the silent Midi grandfather in RenewalController, which this
-- replaces with explicit, attributed, per-season grants.

CREATE TABLE IF NOT EXISTS residence_exceptions (
    season_start_year SMALLINT UNSIGNED NOT NULL,
    bj_user_id        INT UNSIGNED NOT NULL,
    pricing_residence VARCHAR(20)  NOT NULL DEFAULT 'garennois' COMMENT 'garennois | hors-commune',
    reason            VARCHAR(500) NOT NULL,
    granted_by        VARCHAR(190) NOT NULL,
    granted_at        DATETIME     NOT NULL,
    revoked_at        DATETIME     NULL DEFAULT NULL,
    revoked_by        VARCHAR(190) NOT NULL DEFAULT '',
    revoke_reason     VARCHAR(500) NOT NULL DEFAULT '',
    PRIMARY KEY (season_start_year, bj_user_id),
    KEY idx_residence_exceptions_user (bj_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Join flow: an applicant has no bj_user_id until fulfillment creates the BJ
-- user, so the grant lives on the application and FulfillmentService copies it
-- into residence_exceptions once the member exists.
ALTER TABLE applications
    ADD COLUMN pricing_residence        VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'admin-granted price grid; empty = same as residence',
    ADD COLUMN pricing_residence_reason VARCHAR(500) NOT NULL DEFAULT '',
    ADD COLUMN pricing_residence_by     VARCHAR(190) NOT NULL DEFAULT '';

UPDATE applications
   SET pricing_residence        = 'garennois',
       pricing_residence_reason = midi_residency_override_reason
 WHERE midi_residency_override = 1;

-- The midi_residency_override columns are left in place here and dropped in a
-- later migration, once nothing reads them any more.

-- So an order — and the invoice built from it — is self-describing, instead of
-- AdminOpsController::residenceForOrder() re-deriving residence from BJ after
-- the fact (which cannot know an exception was applied at the time).
ALTER TABLE orders
    ADD COLUMN residence         VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'garennois | hors-commune — where the member actually lives',
    ADD COLUMN pricing_residence VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'price grid actually charged; differs from residence only under an exception';

-- What actually applied that season, alongside the rest of the local truth
-- this table already keeps for renewals.
ALTER TABLE member_formulas
    ADD COLUMN pricing_residence VARCHAR(20) NOT NULL DEFAULT '';
