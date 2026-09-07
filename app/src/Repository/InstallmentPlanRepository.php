<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;

/**
 * A renewal paid in 2 or 3 installments. installment 1 is an ordinary order
 * (see OrderRepository) — this table is the parent record: the frozen
 * renewal choice (so bin/charge-installments.php can rebuild the right
 * context weeks later, independent of any session), the remaining schedule,
 * and the SumUp customer/token needed to charge the following installments
 * without the member coming back.
 */
class InstallmentPlanRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array $renewalIntent frozen $_SESSION['renewal_intent'] shape
     * @param array $schedule [{number, amount, due_date, extends_to, status}, ...] for installments 2..N
     */
    public function create(
        int $bjUserId,
        int $seasonStartYear,
        int $installmentCount,
        array $renewalIntent,
        array $schedule,
        string $sumupCustomerId,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO installment_plans
                (bj_user_id, season_start_year, installment_count, renewal_intent, schedule, sumup_customer_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, "active", NOW(), NOW())'
        );
        $stmt->execute([
            $bjUserId,
            $seasonStartYear,
            $installmentCount,
            json_encode($renewalIntent, JSON_UNESCAPED_UNICODE),
            json_encode($schedule, JSON_UNESCAPED_UNICODE),
            $sumupCustomerId,
        ]);
        return $this->findById((int) $this->db->pdo()->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM installment_plans WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** The member's active plan for this season, if any — avoids offering/creating a second one. */
    public function activeFor(int $bjUserId, int $seasonStartYear): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM installment_plans WHERE bj_user_id = ? AND season_start_year = ? AND status = 'active' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$bjUserId, $seasonStartYear]);
        return $stmt->fetch() ?: null;
    }

    /** @return array[] every plan still awaiting a due charge, for bin/charge-installments.php */
    public function allActive(): array
    {
        $stmt = $this->db->pdo()->query("SELECT * FROM installment_plans WHERE status = 'active'");
        return $stmt->fetchAll();
    }

    public function setToken(int $id, string $token): void
    {
        $this->update($id, ['sumup_payment_token' => $token]);
    }

    public function updateSchedule(int $id, array $schedule): void
    {
        $this->update($id, ['schedule' => json_encode($schedule, JSON_UNESCAPED_UNICODE)]);
    }

    public function markStatus(int $id, string $status): void
    {
        $this->update($id, ['status' => $status]);
    }

    private function update(int $id, array $fields): void
    {
        $sets = implode(', ', array_map(fn (string $k) => "`$k` = ?", array_keys($fields)));
        $stmt = $this->db->pdo()->prepare("UPDATE installment_plans SET {$sets}, updated_at = NOW() WHERE id = ?");
        $stmt->execute([...array_values($fields), $id]);
    }
}
