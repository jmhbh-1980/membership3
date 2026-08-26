ALTER TABLE orders MODIFY COLUMN status
    ENUM('pending','paid','fulfilling','fulfilled','failed','canceled','refunded','processed','awaiting_promo_approval')
    NOT NULL DEFAULT 'pending';
