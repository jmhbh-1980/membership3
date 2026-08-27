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
        $this->db->pdo()->prepare('DELETE FROM change_requests WHERE bj_user_id = ?')->execute([self::FAKE_BJ_USER_ID]);
        array_map('unlink', glob($this->configDir . '/*') ?: []);
        rmdir($this->configDir);
    }

    public function testCurrentUncoveredAndNextUnpublished(): void
    {
        // 2026-2027 doesn't exist in config/ — only 2025-2026 does.
        $today = new DateTimeImmutable('2026-03-15');
        $target = $this->renewals->renewalTarget($today, '');

        self::assertSame('open', $target['state']);
        self::assertSame(2025, $target['season']->startYear);
        self::assertFalse($target['late_settlement']);
        self::assertFalse($target['choice_available']);
    }

    public function testCurrentUncoveredNextPublishedButBeforeJuly(): void
    {
        $this->withNextSeasonFile(function () {
            $today = new DateTimeImmutable('2026-05-01'); // before 1 July
            $target = $this->renewals->renewalTarget($today, '');

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
            $target = $this->renewals->renewalTarget($today, '');

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
        $target = $this->renewals->renewalTarget($today, '');

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
        $target = $this->renewals->renewalTarget($today, $coveredThroughCurrent);

        self::assertSame('not_yet_open', $target['state']);
        self::assertSame(2025, $target['season']->startYear); // the *current* season, so the template can name .next()
        self::assertFalse($target['late_settlement']);
        self::assertFalse($target['choice_available']);
    }

    public function testDateEndShortOfOldGraceMarkerButStillFutureIsCovered(): void
    {
        // The exact incident this regression guards: a member (BJ date set by
        // hand, not through this app) is covered through 2026-09-13 — 2 days
        // short of Season(2025)'s old grace-end marker (2026-09-15), but
        // still genuinely in the future as of today. There is no grace-period
        // marker to compare against any more — only whether BJ's own date is
        // still ahead of today — so this must read as covered, not late/Pack
        // été-eligible, even during the July/August late-settlement window.
        $today = new DateTimeImmutable('2026-08-25'); // current = Season(2025), well within "late" territory by old rules
        $target = $this->renewals->renewalTarget($today, '2026-09-13');

        self::assertSame('not_yet_open', $target['state']);
        self::assertFalse($target['late_settlement']);
        self::assertFalse($target['choice_available']);
    }

    public function testCurrentCoveredNextPublishedAndNextUncovered(): void
    {
        $this->withNextSeasonFile(function () {
            $today = new DateTimeImmutable('2026-08-20'); // current = Season(2025)
            $coveredThroughCurrentOnly = '2026-09-15'; // covers current's grace end, not next's (2027-09-15)
            $target = $this->renewals->renewalTarget($today, $coveredThroughCurrentOnly);

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
            $target = $this->renewals->renewalTarget($today, $coveredThroughNext);

            self::assertSame('done', $target['state']);
            self::assertSame(2026, $target['season']->startYear); // the furthest season they're covered through
            self::assertFalse($target['late_settlement']);
            self::assertFalse($target['choice_available']);
        });
    }

    public function testStaleMemberFormulasRowNeverOverridesBj(): void
    {
        // The exact incident this regression guards: a member_formulas row
        // claims the current season is done, but BJ's subscription_date_end
        // doesn't actually reach it (a reverted/mistaken renewal, a manual BJ
        // edit, a leftover test row...). renewalTarget() must trust BJ alone.
        $this->renewals->recordFormula(2025, self::FAKE_BJ_USER_ID, 'heures-pleines', false, false, 0, 0, null);

        $today = new DateTimeImmutable('2026-03-15'); // current = Season(2025)
        $target = $this->renewals->renewalTarget($today, '');

        self::assertSame('open', $target['state']);
        self::assertSame(2025, $target['season']->startYear);
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

    public function testResolveCurrentFormulaBjWinsOverStaleLocalRow(): void
    {
        // The exact scenario this fix targets: an admin corrected the member's
        // tier directly in BJ (now resolves to heures-creuses), but the local
        // member_formulas row still has last season's heures-pleines pick.
        // BJ must win for subscriptionType — no local cache should shadow it.
        $known = ['subscription_type' => 'heures-pleines', 'competitor' => 1];
        $fromBj = ['subscriptionType' => 'heures-creuses', 'isCouple' => false, 'isCompetitor' => false];

        $result = $this->renewals->resolveCurrentFormula($known, $fromBj);

        self::assertSame('heures-creuses', $result['subscriptionType']);
        // competitor has no BJ field at all — member_formulas stays authoritative
        // for it regardless of which side supplied the subscription type.
        self::assertTrue($result['isCompetitor']);
    }

    public function testResolveCurrentFormulaFallsBackToLocalWhenBjUnresolved(): void
    {
        // BJ's subscription_id doesn't resolve to anything (a ticket formula,
        // staff type, or blank) — member_formulas is the only remaining signal.
        $known = ['subscription_type' => 'midi', 'competitor' => 0];

        $result = $this->renewals->resolveCurrentFormula($known, null);

        self::assertSame('midi', $result['subscriptionType']);
        self::assertFalse($result['isCompetitor']);
    }

    public function testResolveCurrentFormulaNeverTransactedUsesBjGuessAlone(): void
    {
        // No member_formulas row at all (never transacted through the app) —
        // BJ's name-derived guess is all there is; competitor defaults false
        // since nothing local exists to say otherwise.
        $fromBj = ['subscriptionType' => 'heures-pleines', 'isCouple' => true, 'isCompetitor' => false];

        $result = $this->renewals->resolveCurrentFormula(null, $fromBj);

        self::assertSame('heures-pleines', $result['subscriptionType']);
        self::assertFalse($result['isCompetitor']);
    }

    public function testResolveCurrentFormulaEverythingUnresolvedReturnsNull(): void
    {
        self::assertNull($this->renewals->resolveCurrentFormula(null, null));
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

    public function testDecideChangeRequestCanRevokeAnAlreadyApprovedRequest(): void
    {
        $this->renewals->createChangeRequest(
            self::FAKE_BJ_USER_ID, 'test@example.com', 'Test Member', 'Ancien abonnement',
            'heures-pleines', false, false, 0, '', 2025,
        );
        $stmt = $this->db->pdo()->prepare('SELECT id FROM change_requests WHERE bj_user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([self::FAKE_BJ_USER_ID]);
        $id = (int) $stmt->fetchColumn();

        $this->renewals->decideChangeRequest($id, true, 'ok');
        self::assertSame('approved', $this->renewals->findChangeRequest($id)['status']);

        // The fix under test: an already-approved row can now be walked back to refused.
        $this->renewals->decideChangeRequest($id, false, 'stale, revoked');
        self::assertSame('refused', $this->renewals->findChangeRequest($id)['status']);

        // Once refused, it's terminal again — a further decision is a no-op.
        $this->renewals->decideChangeRequest($id, true, 'too late');
        self::assertSame('refused', $this->renewals->findChangeRequest($id)['status']);
    }

    public function testMarkLessonsTakenBumpsExistingRowToAtLeastOne(): void
    {
        $this->renewals->recordFormula(2025, self::FAKE_BJ_USER_ID, 'heures-pleines', false, false, 0, 0, null);

        $this->renewals->markLessonsTaken(2025, self::FAKE_BJ_USER_ID);

        self::assertSame(1, $this->renewals->knownFormula(self::FAKE_BJ_USER_ID)['lessons']);
    }

    public function testMarkLessonsTakenNeverLowersAnAlreadyHigherCount(): void
    {
        $this->renewals->recordFormula(2025, self::FAKE_BJ_USER_ID, 'heures-pleines', true, false, 2, 0, null);

        $this->renewals->markLessonsTaken(2025, self::FAKE_BJ_USER_ID);

        self::assertSame(2, $this->renewals->knownFormula(self::FAKE_BJ_USER_ID)['lessons']);
    }

    public function testMarkLessonsTakenIsNoOpWithoutAnExistingRow(): void
    {
        $this->renewals->markLessonsTaken(2025, self::FAKE_BJ_USER_ID);

        self::assertNull($this->renewals->knownFormula(self::FAKE_BJ_USER_ID));
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
