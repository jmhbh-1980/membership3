-- Reconciliation states for orders whose fulfilled outcome may no longer
-- match BJ (e.g. cancelled or refunded outside the app after fulfillment) —
-- there's no live BJ signal for orders/payments at all, so admins record it
-- directly on the order. Reuses the existing 'canceled' value (defined in
-- the enum since 0003 but never set by any code path) and adds 'refunded';
-- both are ordinary order statuses, filterable/summable like the rest.

ALTER TABLE orders
    MODIFY COLUMN status ENUM('pending','paid','fulfilling','fulfilled','failed','canceled','refunded') NOT NULL DEFAULT 'pending';
