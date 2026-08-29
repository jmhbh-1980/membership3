-- Bank transfer as an alternate payment method for join/renewal: an order
-- awaiting_bank_transfer sits outside the normal SumUp flow until an admin
-- manually confirms the money landed in the club's account (see
-- AdminOpsController::decideBankTransfer, PaymentSettlementService::confirmBankTransfer).
-- Mirrors awaiting_promo_approval (0013_order_promo_approval_status.sql).

ALTER TABLE orders
    MODIFY COLUMN status ENUM('pending','paid','fulfilling','fulfilled','failed','canceled','refunded','processed','awaiting_promo_approval','awaiting_bank_transfer') NOT NULL DEFAULT 'pending';

ALTER TABLE orders
    ADD COLUMN payment_method ENUM('online','bank_transfer') NOT NULL DEFAULT 'online' AFTER kind,
    ADD COLUMN bank_transfer_confirmed_at DATETIME NULL DEFAULT NULL AFTER promo_refused_at;
