<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;
use PDOException;

/**
 * credit_notes: one avoir per order, enforced by uq_credit_notes_order — that
 * unique key is the "issue exactly once" guard beneath CreditNoteService's own
 * findByOrderId() pre-check, exactly as InvoiceRepository does for factures.
 *
 * Today an avoir only ever comes from a residence exception granted after the
 * member already paid, and a member has at most one exception per season, so
 * one per order is the right shape.
 */
final class CreditNoteRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function findByOrderId(int $orderId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM credit_notes WHERE order_id = ?');
        $stmt->execute([$orderId]);
        return $stmt->fetch() ?: null;
    }

    /** @param array{number:string, seasonLabel:string, sequence:int} $allocation */
    public function create(
        int $orderId,
        int $invoiceId,
        array $allocation,
        float $amount,
        string $reason,
        string $pdfPath,
        string $issuedBy,
        \DateTimeImmutable $issuedAt,
    ): array {
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO credit_notes (order_id, invoice_id, number, season_label, sequence, amount, reason, pdf_path, issued_by, issued_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $orderId, $invoiceId, $allocation['number'], $allocation['seasonLabel'], $allocation['sequence'],
                $amount, mb_substr($reason, 0, 500), $pdfPath, $issuedBy, $issuedAt->format('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            // uq_credit_notes_order race: another request issued it first — use that one.
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
     * Avoirs this member can see — same join/renewal linkage as
     * InvoiceRepository::findForBjUser(): a renewal order matches bj_user_id
     * directly, a join order through application_people.
     *
     * @return array[] newest first
     */
    public function findForBjUser(int $bjUserId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.* FROM credit_notes c
             JOIN orders o ON o.id = c.order_id
             WHERE o.kind = 'renewal' AND o.bj_user_id = ?
             UNION ALL
             SELECT c.* FROM credit_notes c
             JOIN orders o ON o.id = c.order_id
             JOIN application_people ap ON ap.application_id = o.application_id
             WHERE o.kind = 'join' AND ap.bj_user_id = ?
             ORDER BY issued_at DESC"
        );
        $stmt->execute([$bjUserId, $bjUserId]);
        return $stmt->fetchAll();
    }
}
