-- Optional free-text comment an applicant leaves for the club on the identity
-- step of the join wizard: a question, an availability, a particular situation.
--
-- One per application, not per person: a couple's partner never sees that
-- step. Read by admins on the application page before they decide. It stays in
-- this app and is never copied to Balle Jaune, so it leaves with the
-- application when an admin clears it.

ALTER TABLE applications
    ADD COLUMN applicant_comment VARCHAR(1000) NOT NULL DEFAULT ''
        COMMENT 'optional note from the applicant to the club, typed on the identity step'
        AFTER lessons_count;
