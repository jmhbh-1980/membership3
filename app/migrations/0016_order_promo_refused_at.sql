ALTER TABLE orders
    ADD COLUMN promo_refused_at DATETIME NULL DEFAULT NULL AFTER discount_amount;
