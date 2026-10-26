-- A member on the Formule Tickets asking to join the yearly membership
-- (« Adhérer à l'année » in their space). It goes through the renewal flow's
-- change request, which the club approves, but with what a join requires:
-- a profile photo, and a proof of address when they live in La
-- Garenne-Colombes. Both are uploaded with the request and stored under
-- uploads/renewals/{season}/{bj_user_id}/, the admin reading them before
-- approving.
--
-- The member then pays the 1ère inscription rate, minus their unused tickets
-- at this season's price per ticket; the tickets are taken off their Balle
-- Jaune balance once the payment has gone through.

ALTER TABLE change_requests
    ADD COLUMN ticket_upgrade TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'from the Formule Tickets to a yearly membership'
        AFTER kind,
    ADD COLUMN photo_stored_name VARCHAR(100) NOT NULL DEFAULT '' AFTER ticket_upgrade,
    ADD COLUMN justificatif_stored_name VARCHAR(100) NOT NULL DEFAULT '' AFTER photo_stored_name;
