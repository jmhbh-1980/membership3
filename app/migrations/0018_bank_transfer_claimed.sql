-- Lets a member confirm "I made the transfer" on the waiting page — separate
-- from bank_transfer_confirmed_at, which is the admin's own verification
-- against the bank statement (see AdminOpsController::decideBankTransfer).
-- claimed_at is the member's claim; confirmed_at is the club's proof.

ALTER TABLE orders
    ADD COLUMN bank_transfer_claimed_at DATETIME NULL DEFAULT NULL AFTER bank_transfer_confirmed_at;
