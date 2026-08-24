-- Pack été for new joiners: applications submitted in July/August target the
-- current, almost-over season at a flat rate (50€ cotisation + 5€ licence
-- été, no group lessons, no competitor licence) instead of full price for
-- the upcoming season. Set once at creation (ProspectController::submitStart),
-- purely date-derived, never revisited — same spirit as season_start_year.

ALTER TABLE applications ADD COLUMN summer_pack TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'auto-set for July/August applications: Pack été (50€ cotisation + 5€ licence été), no group lessons, no competitor licence';
