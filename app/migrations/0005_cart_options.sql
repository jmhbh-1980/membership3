-- Formerly added a whole-application licence_kind override column. Licence
-- kind is now always derived live from each person's own `competitor` flag
-- (application_people.competitor), and licence removal is per-person
-- (application_people.licence_removed/licence_removal_reason) — both now
-- defined directly in 0002_applications.sql. Kept as a harmless no-op so
-- the migration filename sequence stays stable.
SET @migration_0005_noop = 1;
