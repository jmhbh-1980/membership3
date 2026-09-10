<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\ResidenceExceptionRepository;
use App\Service\PricingService;
use App\Support\Db;
use PHPUnit\Framework\TestCase;

/**
 * Integration test against the dev MySQL, same convention as
 * RenewalServiceTest: bj_user_ids far outside any real range, cleaned up in
 * tearDown().
 */
final class ResidenceExceptionRepositoryTest extends TestCase
{
    private const int SEASON = 2999;
    private const int USER_A = 999999101;
    private const int USER_B = 999999102;

    private ResidenceExceptionRepository $exceptions;
    private Db $db;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->exceptions = new ResidenceExceptionRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->pdo()
            ->prepare('DELETE FROM residence_exceptions WHERE bj_user_id IN (?, ?)')
            ->execute([self::USER_A, self::USER_B]);
    }

    public function testGrantThenReadBack(): void
    {
        $this->exceptions->grant(self::SEASON, self::USER_A, PricingService::RESIDENCE_GARENNOIS, 'Bénévole', 'admin@example.org');

        $active = $this->exceptions->findActive(self::SEASON, self::USER_A);
        self::assertNotNull($active);
        self::assertSame(PricingService::RESIDENCE_GARENNOIS, $active['pricing_residence']);
        self::assertSame('Bénévole', $active['reason']);
        self::assertSame('admin@example.org', $active['granted_by']);
        self::assertSame(PricingService::RESIDENCE_GARENNOIS, $this->exceptions->overrideFor(self::SEASON, self::USER_A));
    }

    public function testGrantIsScopedToOneSeason(): void
    {
        $this->exceptions->grant(self::SEASON, self::USER_A, PricingService::RESIDENCE_GARENNOIS, 'Bénévole', 'admin@example.org');

        // The whole point of the per-season key: next season starts blank and
        // must be re-granted deliberately.
        self::assertNull($this->exceptions->findActive(self::SEASON + 1, self::USER_A));
        self::assertSame('', $this->exceptions->overrideFor(self::SEASON + 1, self::USER_A));
    }

    public function testRevokeMakesItInertButKeepsTheTrace(): void
    {
        $this->exceptions->grant(self::SEASON, self::USER_A, PricingService::RESIDENCE_GARENNOIS, 'Bénévole', 'admin@example.org');
        $this->exceptions->revoke(self::SEASON, self::USER_A, 'other@example.org', 'Ne remplit plus les conditions');

        self::assertNull($this->exceptions->findActive(self::SEASON, self::USER_A));
        self::assertSame('', $this->exceptions->overrideFor(self::SEASON, self::USER_A));

        $row = $this->exceptions->find(self::SEASON, self::USER_A);
        self::assertNotNull($row, 'a revoked grant must stay visible — that is the point of making these explicit');
        self::assertNotNull($row['revoked_at']);
        self::assertSame('other@example.org', $row['revoked_by']);
        self::assertSame('Ne remplit plus les conditions', $row['revoke_reason']);
    }

    public function testReGrantingClearsTheRevocationRatherThanStacking(): void
    {
        $this->exceptions->grant(self::SEASON, self::USER_A, PricingService::RESIDENCE_GARENNOIS, 'Premier motif', 'admin@example.org');
        $this->exceptions->revoke(self::SEASON, self::USER_A, 'admin@example.org', 'Erreur');
        $this->exceptions->grant(self::SEASON, self::USER_A, PricingService::RESIDENCE_GARENNOIS, 'Second motif', 'other@example.org');

        $active = $this->exceptions->findActive(self::SEASON, self::USER_A);
        self::assertNotNull($active);
        self::assertSame('Second motif', $active['reason']);
        self::assertSame('other@example.org', $active['granted_by']);
        self::assertSame('', $active['revoke_reason']);
    }

    public function testOverridesForSeasonBatchesAndSkipsRevoked(): void
    {
        $this->exceptions->grant(self::SEASON, self::USER_A, PricingService::RESIDENCE_GARENNOIS, 'Bénévole', 'admin@example.org');
        $this->exceptions->grant(self::SEASON, self::USER_B, PricingService::RESIDENCE_HORS_COMMUNE, 'Adresse non vérifiée', 'admin@example.org');
        $this->exceptions->revoke(self::SEASON, self::USER_B, 'admin@example.org', '');

        $map = $this->exceptions->overridesForSeason(self::SEASON, [self::USER_A, self::USER_B, 999999999]);

        self::assertSame([self::USER_A => PricingService::RESIDENCE_GARENNOIS], $map);
    }

    public function testOverridesForSeasonWithNoIdsIssuesNoQuery(): void
    {
        self::assertSame([], $this->exceptions->overridesForSeason(self::SEASON, []));
    }
}
