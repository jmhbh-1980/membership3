<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;

/**
 * Shared writer for audit_log (browsable read-only at /admin/journal-audit).
 * Admin controllers each keep their own inline audit() helper for actions
 * with a human actor; this is for the service layer, where events are
 * triggered automatically (a SumUp webhook, a payer's return-URL visit) with
 * no admin behind them — actor is 'system' for those, per the table's own
 * column comment ('email or "system"').
 */
class AuditLogRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function log(string $actor, string $action, string $entity, string $entityId, array $details = []): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO audit_log (actor, action, entity, entity_id, details, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$actor, $action, $entity, $entityId, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }
}
