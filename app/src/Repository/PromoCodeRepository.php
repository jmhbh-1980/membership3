<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;

class PromoCodeRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function create(
        string $code,
        string $kind,
        float $value,
        string $scope,
        ?int $maxUses,
        ?string $expiresAt,
        string $note,
        string $createdBy,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO promo_codes (code, kind, value, scope, max_uses, expires_at, note, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$code, $kind, $value, $scope, $maxUses, $expiresAt, $note, $createdBy]);

        return $this->findById((int) $this->db->pdo()->lastInsertId());
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM promo_codes WHERE code = ?');
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM promo_codes WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array[] every non-archived code, newest first */
    public function all(): array
    {
        $stmt = $this->db->pdo()->query('SELECT * FROM promo_codes WHERE archived_at IS NULL ORDER BY created_at DESC');
        return $stmt->fetchAll();
    }

    /** @return array[] every archived code, newest first */
    public function allArchived(): array
    {
        $stmt = $this->db->pdo()->query('SELECT * FROM promo_codes WHERE archived_at IS NOT NULL ORDER BY created_at DESC');
        return $stmt->fetchAll();
    }

    /** Orders that actually claimed the code — canceled/failed checkouts don't count against max_uses. */
    public function usageCount(int $id): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM orders WHERE promo_code_id = ? AND status NOT IN ('canceled', 'failed')"
        );
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Whether the code has ever been attached to an order that actually went
     * through — as opposed to a still-pending or abandoned checkout. Once
     * true, the code's definition may have already shaped a real payment and
     * locks against edit/delete (see AdminPromoCodeController).
     */
    public function hasSuccessfulUsage(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM orders WHERE promo_code_id = ? AND status NOT IN ('pending', 'canceled', 'failed') LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE promo_codes SET active = ? WHERE id = ?');
        $stmt->execute([(int) $active, $id]);
    }

    /** @param array{code:string,kind:string,value:float,scope:string,maxUses:?int,expiresAt:?string,note:string} $fields */
    public function update(int $id, array $fields): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE promo_codes SET code = ?, kind = ?, value = ?, scope = ?, max_uses = ?, expires_at = ?, note = ? WHERE id = ?'
        );
        $stmt->execute([
            $fields['code'], $fields['kind'], $fields['value'], $fields['scope'],
            $fields['maxUses'], $fields['expiresAt'], $fields['note'], $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM promo_codes WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function archive(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE promo_codes SET archived_at = NOW(), active = 0 WHERE id = ?');
        $stmt->execute([$id]);
    }
}
