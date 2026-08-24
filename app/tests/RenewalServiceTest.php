<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\PricingService;
use App\Service\RenewalService;
use App\Support\Db;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: RenewalService reads/writes member_formulas via a real
 * Db connection (dev MySQL, same one used throughout local dev — see
 * CLAUDE.md). A bj_user_id far outside any real range is used so these
 * queries never touch real data; tearDown() clears any rows written for it.
 *
 * $configDir is a throwaway copy of pricing_data/, not the real one — several
 * tests assert on whether pricing.2026-2027.php exists, which must stay
 * deterministic regardless of whatever an admin has actually published in
 * the real dev app (see withNextSeasonFile()).
 */
final class RenewalServiceTest extends TestCase
{
    private const int FAKE_BJ_USER_ID = 999999001;

    private RenewalService $renewals;
    private string $configDir;
    private Db $db;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/renewal_service_test_' . bin2hex(random_bytes(4));
        mkdir($this->configDir);
        copy(dirname(__DIR__, 2) . '/pricing_data/pricing.2025-2026.php', $this->configDir . '/pricing.2025-2026.php');
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $pricing = new PricingService($this->configDir);
        $this->renewals = new RenewalService($this->db, $pricing);
    }

    protected function tearDown(): void
    {
        $this->db->pdo()->prepare('DELETE FROM member_formulas WHERE bj_user_id = ?')->execute([self::FAKE_BJ_USER_ID]);
        array_map('unlink', glob($this->configDir . '/*') ?: []);
        rmdir($this->configDir);
    }

    public function testCurrentUncoveredAndNextUnpublished(): void
    {
        // 2026-2027 doesn't exist in config/ — only 2025-2026 does.
        $today = new DateTimeImmutable('2026-03-15');
        $target = $this->renewals->renewalTarget($today, '', self::FAKE_BJ_USER_ID);

        self::assertSame('open', $target['state']);
        self::assertSame(2025, $target['season']->startYear);
        self::assertFalse($target['late_settlement']);
        self::assertFalse($target['choice_available']);
    }

    public function testCurrentUncoveredNextPublishedButBeforeJuly(): void
    {
        $this->withNextSeasonFile(function () {
            $today = new DateTimeImmutable('2026-05-01'); // before 1 July
            $target = $this->renewals->renewalTarget($today, '', self::FAKE_BJ_USER_ID);

            self::assertSame('open', $target['state']);
            self::assertSame(2025, $target['season']->startYear);
            self::assertFalse($target['late_settlement']); // too early for the late fee, even though next is published
            self::assertTrue($target['next_published']);
            self::assertFalse($target['choice_available']); // no late fee yet, so nothing to choose between
        });
    }

    public function testCurrentUncoveredNextPublishedOnOrAfterJuly(): void
    {
        $this->withNextSeasonFile(function () {
            $today = new DateTimeImmutable('2026-07-01'); // exactly the boundary, included
            $target = $this->renewals->renewalTarget($today, '', self::FAKE_BJ_USER_ID);

            self::assertSame('open', $target['state']);
            self::assertSame(2025, $target['season']->startYear);
            self::assertTrue($target['late_settlement']);
            self::assertTrue($target['next_published']);
            self::assertTrue($target['choice_available']); // late + next published → Pack été vs. next season
        });
    }

    public function testCurrentUncoveredOnOrAfterJulyRegardlessOfNextPublished(): void
    {
        // The regression this fixes: late_settlement (and the prorated/flat-fee renewal
        // pricing it drives) must not depend on next season's catalogue being published —
        // that governs a separate concern (whether to also offer next season).
        $today = new DateTimeImmutable('2026-08-20'); // no pricing.2026-2027.php on disk
        $target = $this->renewals->renewalTarget($today, '', self::FAKE_BJ_USER_ID);

        self::assertSame('open', $target['state']);
        self::assertSame(2025, $target['season']->startYear);
        self::assertTrue($target['late_settlement']);
        self::assertFalse($target['next_published']);
        self::assertFalse($target['choice_available']); // late, but nothing to choose between since next isn't published
    }

    public function testCurrentCoveredAndNextUnpublished(): void
    {
        $today = new DateTimeImmutable('2026-03-15'); // current = Season(2025)
        $coveredThroughCurrent = '2026-09-15'; // Season(2025)->graceEnd()
        $target = $this->renewals->renewalTarget($today, $coveredThroughCurrent, self::FAKE_BJ_USER_ID);

        self::assertSame('not_yet_open', $target['state']);
        self::assertSame(2025, $target['season']->startYear); // the *current* season, so the template can name .next()
        self::assertFalse($target['late_settlement']);
        self::assertFalse($target['choice_available']);
    }

    public function testCurrentCoveredNextPublishedAndNextUncovered(): void
    {
        $this->withNextSeasonFile(function () {
            $today = new DateTimeImmutable('2026-08-20'); // current = Season(2025)
            $coveredThroughCurrentOnly = '2026-09-15'; // covers current's grace end, not next's (2027-09-15)
            $target = $this->renewals->renewalTarget($today, $coveredThroughCurrentOnly, self::FAKE_BJ_USER_ID);

            self::assertSame('open', $target['state']);
            self::assertSame(2026, $target['season']->startYear); // next season
            self::assertFalse($target['late_settlement']);
            self::assertFalse($target['choice_available']);
        });
    }

    public function testBothSeasonsCovered(): void
    {
        $this->withNextSeasonFile(function () {
            $today = new DateTimeImmutable('2026-08-20');
            $coveredThroughNext = '2027-09-15'; // covers both seasons' grace ends
            $target = $this->renewals->renewalTarget($today, $coveredThroughNext, self::FAKE_BJ_USER_ID);

            self::assertSame('done', $target['state']);
            self::assertSame(2026, $target['season']->startYear); // the furthest season they're covered through
            self::assertFalse($target['late_settlement']);
            self::assertFalse($target['choice_available']);
        });
    }

    public function testValidSeasonLabelsNoRecordNoDate(): void
    {
        $result = $this->renewals->validSeasonLabels(self::FAKE_BJ_USER_ID, '');

        self::assertSame([], $result['seasons']);
        self::assertFalse($result['mismatch']);
    }

    public function testValidSeasonLabelsBjDateOnlyLegacyMember(): void
    {
        // No member_formulas row (never renewed through the app) — pure legacy BJ date.
        $result = $this->renewals->validSeasonLabels(self::FAKE_BJ_USER_ID, '2026-09-15');

        self::assertSame(['2025-2026'], $result['seasons']);
        self::assertFalse($result['mismatch']);
    }

    public function testValidSeasonLabelsAppAndBjAgree(): void
    {
        $this->renewals->recordFormula(2026, self::FAKE_BJ_USER_ID, 'heures-pleines', false, false, 0, 0, null);
        $result = $this->renewals->validSeasonLabels(self::FAKE_BJ_USER_ID, '2027-09-15');

        self::assertSame(['2026-2027'], $result['seasons']);
        self::assertFalse($result['mismatch']);
    }

    public function testValidSeasonLabelsBjEditedBehindApp(): void
    {
        // The exact scenario found in dev: member_formulas says 2026-2027, but BJ's
        // date_end was hand-edited back to only cover 2025-2026's grace end.
        $this->renewals->recordFormula(2026, self::FAKE_BJ_USER_ID, 'heures-pleines', false, false, 0, 0, null);
        $result = $this->renewals->validSeasonLabels(self::FAKE_BJ_USER_ID, '2026-09-15');

        self::assertSame(['2025-2026', '2026-2027'], $result['seasons']);
        self::assertTrue($result['mismatch']);
    }

    public function testValidSeasonLabelsAppRecordButBjDateEmpty(): void
    {
        $this->renewals->recordFormula(2025, self::FAKE_BJ_USER_ID, 'heures-pleines', false, false, 0, 0, null);
        $result = $this->renewals->validSeasonLabels(self::FAKE_BJ_USER_ID, '');

        self::assertSame(['2025-2026'], $result['seasons']);
        self::assertTrue($result['mismatch']);
    }

    public function testResolveCoupleStatusBjSaysCouple(): void
    {
        // BJ wins even when the local row disagrees.
        $bjUser = ['custom2' => '1', 'custom3' => '1407878'];
        $known = ['is_couple' => 0, 'partner_bj_user_id' => 0];

        $result = $this->renewals->resolveCoupleStatus($bjUser, $known, null);

        self::assertTrue($result['isCouple']);
        self::assertSame(1407878, $result['partnerBjUserId']);
    }

    public function testResolveCoupleStatusBjSaysCoupleWithNoPartnerYet(): void
    {
        $bjUser = ['custom2' => '1', 'custom3' => ''];

        $result = $this->renewals->resolveCoupleStatus($bjUser, null, null);

        self::assertTrue($result['isCouple']);
        self::assertSame(0, $result['partnerBjUserId']);
    }

    public function testResolveCoupleStatusBjBlankFallsBackToLocalRow(): void
    {
        // BJ hasn't been written yet (pre-migration transactor) — local member_formulas wins.
        $bjUser = ['custom2' => '', 'custom3' => ''];
        $known = ['is_couple' => 1, 'partner_bj_user_id' => 1407878];

        $result = $this->renewals->resolveCoupleStatus($bjUser, $known, null);

        self::assertTrue($result['isCouple']);
        self::assertSame(1407878, $result['partnerBjUserId']);
    }

    public function testResolveCoupleStatusBjAndLocalBlankFallsBackToLegacyGuess(): void
    {
        // Never transacted through the app, never touched by this migration —
        // only the legacy BJ subscription name says "Couple". No partner
        // linkage is derivable from a name alone.
        $bjUser = ['custom2' => '', 'custom3' => ''];
        $legacyGuess = ['subscriptionType' => 'heures-pleines', 'isCouple' => true, 'isCompetitor' => false];

        $result = $this->renewals->resolveCoupleStatus($bjUser, null, $legacyGuess);

        self::assertTrue($result['isCouple']);
        self::assertSame(0, $result['partnerBjUserId']);
    }

    public function testResolveCoupleStatusEverythingBlank(): void
    {
        $bjUser = ['custom2' => '', 'custom3' => ''];

        $result = $this->renewals->resolveCoupleStatus($bjUser, null, null);

        self::assertFalse($result['isCouple']);
        self::assertSame(0, $result['partnerBjUserId']);
    }

    /** Temporarily publishes a 2026-2027 pricing file (copy of 2025-2026's) for the duration of $fn. */
    private function withNextSeasonFile(callable $fn): void
    {
        $path = $this->configDir . '/pricing.2026-2027.php';
        copy($this->configDir . '/pricing.2025-2026.php', $path);
        try {
            $fn();
        } finally {
            unlink($path);
        }
    }
}
