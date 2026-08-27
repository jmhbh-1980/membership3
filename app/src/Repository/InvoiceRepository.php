<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;
use PDOException;

/**
 * invoices: one row per join/renewal order, enforced by uq_invoices_order —
 * that unique key is the "generate exactly once" idempotency guard beneath
 * InvoiceService's own findByOrderId() pre-check.
 */
final class InvoiceRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function findByOrderId(int $orderId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM invoices WHERE order_id = ?');
        $stmt->execute([$orderId]);
        return $stmt->fetch() ?: null;
    }

    /** @param array{number:string, seasonLabel:string, sequence:int} $allocation */
    public function create(int $orderId, array $allocation, string $pdfPath, \DateTimeImmutable $issuedAt, float $amount): array
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO invoices (order_id, number, season_label, sequence, amount, pdf_path, issued_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $orderId, $allocation['number'], $allocation['seasonLabel'], $allocation['sequence'],
                $amount, $pdfPath, $issuedAt->format('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            // uq_invoices_order race: another request generated it first — use that one.
            if ((int) $e->getCode() === 23000) {
                $existing = $this->findByOrderId($orderId);
                if ($existing !== null) {
                    return $existing;
                }
            }
            throw $e;
        }

        return $this->findByOrderId($orderId);
    }

    /**
     * All invoices this member can see — same join/renewal linkage as
     * OrderRepository::latestFulfilledForBjUser(): renewal orders match
     * bj_user_id directly, join orders through application_people (an order's
     * own bj_user_id is 0 for a join, the BJ account not existing yet when it
     * was created).
     *
     * @return array[] newest first
     */
    public function findForBjUser(int $bjUserId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.* FROM invoices i
             JOIN orders o ON o.id = i.order_id
             WHERE o.kind = 'renewal' AND o.bj_user_id = ?
             UNION ALL
             SELECT i.* FROM invoices i
             JOIN orders o ON o.id = i.order_id
             JOIN application_people ap ON ap.application_id = o.application_id
             WHERE o.kind = 'join' AND ap.bj_user_id = ?
             ORDER BY issued_at DESC"
        );
        $stmt->execute([$bjUserId, $bjUserId]);
        return $stmt->fetchAll();
    }

    /** Ownership-checked single lookup for the member download route — must never trust the ID alone. */
    public function findByIdForBjUser(int $id, int $bjUserId): ?array
    {
        foreach ($this->findForBjUser($bjUserId) as $invoice) {
            if ((int) $invoice['id'] === $id) {
                return $invoice;
            }
        }
        return null;
    }
}
