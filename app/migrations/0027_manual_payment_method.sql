-- A payment the club took outside the app (cash, cheque, transfer, card on
-- another terminal, coupons sport…) and recorded afterwards by an admin, from
-- the member's page or with bin/backfill_manual_join.php. Its date, method and
-- note live in the order's meta (actualPayment*).
--
-- Those orders used to be filed as 'bank_transfer', the only non-online value
-- there was, so the order page showed them as a bank transfer with a transfer
-- reference that never existed. The UPDATE refiles every one already recorded.

ALTER TABLE orders
    MODIFY COLUMN payment_method ENUM('online','bank_transfer','manual') NOT NULL DEFAULT 'online';

UPDATE orders SET payment_method = 'manual' WHERE meta LIKE '%"manualReconciliation":true%';
