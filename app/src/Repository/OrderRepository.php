<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;

/**
 * Orders and their payment/fulfillment state machine:
 * pending → paid → fulfilling → fulfilled (failed/canceled aside).
 * The paid→fulfilling transition is the idempotency claim: whichever of the
 * webhook or the return URL wins it performs fulfillment exactly once.
 *
 * A promo-code order instead starts at awaiting_promo_approval → pending
 * (admin approves, only then is a SumUp checkout even created) or →
 * canceled (admin refuses, checkout_id stays empty since SumUp was never
 * involved — see hasRefusedPromoUsage()).
 */
class OrderRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array $lines cart lines (serialized as JSON)
     * @param array $meta  kind-specific fulfillment data (serialized as JSON)
     */
    public function create(
        string $kind,
        ?int $applicationId,
        int $bjUserId,
        string $email,
        float $amount,
        array $lines,
        array $meta = [],
        ?int $promoCodeId = null,
        float $discountAmount = 0.0,
    ): array {
        $reference = self::uuid();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO orders (kind, application_id, bj_user_id, email, amount, cart_lines, meta, promo_code_id, discount_amount, checkout_reference, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $kind,
            $applicationId,
            $bjUserId,
            $email,
            $amount,
            json_encode($lines, JSON_UNESCAPED_UNICODE),
            json_encode($meta, JSON_UNESCAPED_UNICODE),
            $promoCodeId,
            $discountAmount,
            $reference,
        ]);

        return $this->findByReference($reference);
    }

    public function findByReference(string $reference): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM orders WHERE checkout_reference = ?');
        $stmt->execute([$reference]);
        return $stmt->fetch() ?: null;
    }

    public function findByCheckoutId(string $checkoutId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM orders WHERE checkout_id = ?');
        $stmt->execute([$checkoutId]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Most recent fulfilled join/renewal order for a member — used to show the exact
     * payment date/time. Join orders carry bj_user_id = 0 (the BJ account doesn't exist
     * yet when the order is created), so that side is matched via application_people,
     * populated by fulfillment once the account is created.
     */
    public function latestFulfilledForBjUser(int $bjUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT o.* FROM orders o
             WHERE o.kind = 'renewal' AND o.bj_user_id = ? AND o.status = 'fulfilled'
             UNION ALL
             SELECT o.* FROM orders o
             JOIN application_people ap ON ap.application_id = o.application_id
             WHERE o.kind = 'join' AND ap.bj_user_id = ? AND o.status = 'fulfilled'
             ORDER BY fulfilled_at DESC LIMIT 1"
        );
        $stmt->execute([$bjUserId, $bjUserId]);
        return $stmt->fetch() ?: null;
    }

    /** Existing awaiting-approval join order for this application, if any — avoids creating a duplicate approval request. */
    public function findAwaitingPromoApprovalByApplication(int $applicationId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM orders WHERE application_id = ? AND status = 'awaiting_promo_approval' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$applicationId]);
        return $stmt->fetch() ?: null;
    }

    /** Existing awaiting-approval renewal order for this member, if any — avoids creating a duplicate approval request. */
    public function findAwaitingPromoApprovalByBjUser(int $bjUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM orders WHERE bj_user_id = ? AND kind = 'renewal' AND status = 'awaiting_promo_approval' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$bjUserId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Whether this member already had a renewal promo-code order refused for
     * this exact code. promo_refused_at is set only by decidePendingOrder()'s
     * refuse branch — a deliberate club decision — so it can't be confused
     * with an admin cancelling an unrelated stale/duplicate order via the
     * generic "Annuler" action. Join doesn't need this check —
     * applications.promo_code is cleared directly on refusal, so a refused
     * code simply isn't present to re-resolve on the next attempt.
     */
    public function hasRefusedPromoUsage(int $bjUserId, int $promoCodeId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM orders WHERE bj_user_id = ? AND kind = 'renewal' AND promo_code_id = ? AND promo_refused_at IS NOT NULL LIMIT 1"
        );
        $stmt->execute([$bjUserId, $promoCodeId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array[] orders currently awaiting a promo-code approval decision, oldest first */
    public function awaitingPromoApproval(): array
    {
        $stmt = $this->db->pdo()->query("SELECT * FROM orders WHERE status = 'awaiting_promo_approval' ORDER BY created_at ASC");
        return $stmt->fetchAll();
    }

    public function update(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $sets = implode(', ', array_map(fn (string $k) => "`$k` = ?", array_keys($fields)));
        $stmt = $this->db->pdo()->prepare("UPDATE orders SET {$sets}, updated_at = NOW() WHERE id = ?");
        $stmt->execute([...array_values($fields), $id]);
    }

    /** Atomically transitions status; returns true when this call won the transition. */
    public function transition(int $id, string $from, string $to): bool
    {
        $stmt = $this->db->pdo()->prepare('UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?');
        $stmt->execute([$to, $id, $from]);
        return $stmt->rowCount() === 1;
    }

    public function addLessonEnrollment(int $seasonStartYear, int $bjUserId, string $firstname, string $lastname, string $email, int $orderId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO lesson_enrollments (season_start_year, bj_user_id, firstname, lastname, email, order_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$seasonStartYear, $bjUserId, $firstname, $lastname, $email, $orderId]);
    }

    public function isEnrolledInLessons(int $bjUserId, int $seasonStartYear): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM lesson_enrollments WHERE bj_user_id = ? AND season_start_year = ? LIMIT 1'
        );
        $stmt->execute([$bjUserId, $seasonStartYear]);
        return $stmt->fetchColumn() !== false;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
