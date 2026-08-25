-- Third and final order-disposition state: "processed" (Traitée) — admin
-- follow-up is done, nothing wrong. Alongside 'canceled'/'refunded', these
-- three are the one-way outcomes that archive an order out of the active
-- admin list (see AdminOpsController::ordersHistory/archivedOrders).

ALTER TABLE orders
    MODIFY COLUMN status ENUM('pending','paid','fulfilling','fulfilled','failed','canceled','refunded','processed') NOT NULL DEFAULT 'pending';
