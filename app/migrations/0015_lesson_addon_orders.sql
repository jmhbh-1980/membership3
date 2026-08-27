-- New order kind: a standalone, member-initiated "cours collectifs" purchase
-- for someone who already renewed without it (see FulfillmentService's
-- fulfillLessonAddon and LessonSignupController). 'change' stays unused.

ALTER TABLE orders
    MODIFY COLUMN kind ENUM('join','renewal','credits','change','lessons') NOT NULL;
