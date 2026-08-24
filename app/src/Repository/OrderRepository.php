<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;

/**
 * Orders and their payment/fulfillment state machine:
 * pending → paid → fulfilling → fulfilled (failed/canceled aside).
 * The paid→fulfilling transition is the idempotency claim: whichever of the
 * webhook or the return URL wins it performs fulfillment exactly once.
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
    public function create(string $kind, ?int $applicationId, int $bjUserId, string $email, float $amount, array $lines, array $meta = []): array
    {
        $reference = self::uuid();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO orders (kind, application_id, bj_user_id, email, amount, cart_lines, meta, checkout_reference, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $kind,
            $applicationId,
            $bjUserId,
            $email,
            $amount,
            json_encode($lines, JSON_UNESCAPED_UNICODE),
            json_encode($meta, JSON_UNESCAPED_UNICODE),
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

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
