<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;

/**
 * Admin-granted residence pricing exceptions: this member is read at the
 * Garennois grid for this season even though their postcode says otherwise
 * (or, more rarely, the reverse). See PricingService's class docblock for the
 * fact/tariff split, and migration 0022 for why the scope is one season.
 *
 * Renewals only — an applicant has no bj_user_id yet, so the join flow carries
 * its own grant on applications.pricing_residence and FulfillmentService
 * copies it here once the BJ user exists.
 *
 * Revoking keeps the row (who granted it, who took it back, and why) rather
 * than deleting it: the point of making these explicit is that they leave a
 * trace. findActive() is what every pricing path calls, and it ignores revoked
 * rows, so a revoked exception is inert without being invisible.
 */
class ResidenceExceptionRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** The grant in force for this member and season, or null. Revoked rows never come back. */
    public function findActive(int $seasonStartYear, int $bjUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM residence_exceptions
              WHERE season_start_year = ? AND bj_user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$seasonStartYear, $bjUserId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The pricing residence in force, ready to hand to PricingService — empty
     * string when there is no exception, which is what
     * PricingService::pricingResidence() expects for "no override".
     */
    public function overrideFor(int $seasonStartYear, int $bjUserId): string
    {
        return (string) ($this->findActive($seasonStartYear, $bjUserId)['pricing_residence'] ?? '');
    }

    /** Any row for this member and season, revoked included — for the admin form's history. */
    public function find(int $seasonStartYear, int $bjUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM residence_exceptions WHERE season_start_year = ? AND bj_user_id = ?'
        );
        $stmt->execute([$seasonStartYear, $bjUserId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Grants (or re-grants after a revoke) the exception. One row per member
     * per season, so re-granting reuses it and clears the revocation rather
     * than stacking a second grant.
     */
    public function grant(
        int $seasonStartYear,
        int $bjUserId,
        string $pricingResidence,
        string $reason,
        string $grantedBy,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO residence_exceptions
                (season_start_year, bj_user_id, pricing_residence, reason, granted_by, granted_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                pricing_residence = VALUES(pricing_residence),
                reason            = VALUES(reason),
                granted_by        = VALUES(granted_by),
                granted_at        = NOW(),
                revoked_at        = NULL,
                revoked_by        = "",
                revoke_reason     = ""'
        );
        $stmt->execute([
            $seasonStartYear,
            $bjUserId,
            $pricingResidence,
            mb_substr($reason, 0, 500),
            $grantedBy,
        ]);
    }

    public function revoke(int $seasonStartYear, int $bjUserId, string $revokedBy, string $reason): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE residence_exceptions
                SET revoked_at = NOW(), revoked_by = ?, revoke_reason = ?
              WHERE season_start_year = ? AND bj_user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$revokedBy, mb_substr($reason, 0, 500), $seasonStartYear, $bjUserId]);
    }

    /** @return array[] every grant for a season, active first then revoked, most recent first */
    public function forSeason(int $seasonStartYear): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM residence_exceptions
              WHERE season_start_year = ?
              ORDER BY revoked_at IS NOT NULL, granted_at DESC'
        );
        $stmt->execute([$seasonStartYear]);
        return $stmt->fetchAll();
    }

    /**
     * Active grants for a set of members in one query — the renewal campaign
     * and change-request lists show a badge per row and must not issue one
     * SELECT per member.
     *
     * @param int[] $bjUserIds
     * @return array<int, string> bj_user_id => pricing_residence
     */
    public function overridesForSeason(int $seasonStartYear, array $bjUserIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $bjUserIds)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT bj_user_id, pricing_residence FROM residence_exceptions
              WHERE season_start_year = ? AND revoked_at IS NULL AND bj_user_id IN ({$in})"
        );
        $stmt->execute([$seasonStartYear, ...$ids]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['bj_user_id']] = (string) $row['pricing_residence'];
        }
        return $map;
    }

    /** Seasons that have at least one grant, most recent first — drives the admin list's season picker. */
    public function seasonsWithGrants(): array
    {
        return array_map(
            'intval',
            $this->db->pdo()
                ->query('SELECT DISTINCT season_start_year FROM residence_exceptions ORDER BY season_start_year DESC')
                ->fetchAll(\PDO::FETCH_COLUMN)
        );
    }
}
