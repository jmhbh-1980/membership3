<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\ApplicationRepository;
use App\Repository\OrderRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\CoupleLinks;
use App\Service\PricingService;
use App\Service\RenewalService;
use App\Support\Db;
use PHPUnit\Framework\TestCase;

/**
 * Where each record keeps a couple's other half — the thing every admin page
 * now relies on to mention partner B while showing partner A. Each record type
 * stores the partner somewhere different, and the fallbacks exist because real
 * orders were fulfilled with the partner missing from one place but not the
 * other (bin/backfill_2026_couples.php), so each path is pinned on its own.
 *
 * Integration test against the dev MySQL, same convention as
 * MemberHistoryQueriesTest. Balle Jaune is never reached: names are either
 * passed in as already known, or come back as the "adhérent #id" fallback the
 * pages show when BJ is down — the empty base URL makes any call fail fast.
 */
final class CoupleLinksTest extends TestCase
{
    private const int SEASON = 2999;
    private const int PAYER = 999999501;
    private const int PARTNER = 999999502;
    private const int OTHER = 999999503;
    private const string EMAIL_PREFIX = 'couple-links-test-';

    private CoupleLinks $couples;
    private ApplicationRepository $applications;
    private OrderRepository $orders;
    private RenewalService $renewals;
    private Db $db;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->applications = new ApplicationRepository($this->db);
        $this->orders = new OrderRepository($this->db);
        $this->renewals = new RenewalService($this->db, new PricingService(dirname(__DIR__, 2) . '/pricing_data'));
        $logger = new \App\Support\Logger(sys_get_temp_dir() . '/couple_links_test.log');
        $this->couples = new CoupleLinks(new BalleJauneClient('', '', $logger), $this->renewals, $this->db);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM orders WHERE email LIKE ?')->execute([self::EMAIL_PREFIX . '%']);
        $pdo->prepare('DELETE FROM applications WHERE email LIKE ?')->execute([self::EMAIL_PREFIX . '%']);
        $pdo->prepare('DELETE FROM member_formulas WHERE bj_user_id IN (?, ?, ?)')->execute([self::PAYER, self::PARTNER, self::OTHER]);
    }

    private function joinOrder(bool $isCouple, bool $withPartner = true): array
    {
        $app = $this->applications->create(self::EMAIL_PREFIX . bin2hex(random_bytes(4)) . '@example.org', self::SEASON);
        $id = (int) $app['id'];
        $this->applications->update($id, ['is_couple' => (int) $isCouple]);
        $this->applications->savePerson($id, 1, ['firstname' => 'Pascale', 'lastname' => 'PAYEUR', 'birthdate' => '1980-01-01']);
        if ($isCouple && $withPartner) {
            $this->applications->savePerson($id, 2, ['firstname' => 'Marie', 'lastname' => 'CONJOINT', 'birthdate' => '1981-01-01']);
        }
        return $this->orders->create('join', $id, 0, self::EMAIL_PREFIX . 'order@example.org', 200.0, []);
    }

    private function renewalOrder(int $payer, array $meta): array
    {
        return $this->orders->create('renewal', null, $payer, self::EMAIL_PREFIX . 'order@example.org', 200.0, [], $meta);
    }

    private function formula(int $bjUserId, bool $isCouple, int $partner, ?int $orderId, int $season = self::SEASON): void
    {
        $this->renewals->recordFormula($season, $bjUserId, 'heures-pleines', $isCouple, false, 0, $partner, $orderId);
    }

    public function testAJoinOrderPairsTheApplicantWithThePartnerOnTheApplication(): void
    {
        $order = $this->joinOrder(isCouple: true);

        $pair = $this->couples->forOrder($order);

        self::assertSame(['name' => 'PAYEUR Pascale', 'bjUserId' => 0], $pair['payer']);
        self::assertSame(['name' => 'CONJOINT Marie', 'bjUserId' => 0], $pair['partner']);
    }

    public function testAnIndividualOrderIsNotACouple(): void
    {
        self::assertNull($this->couples->forOrder($this->joinOrder(isCouple: false)));
        self::assertNull($this->couples->forOrder($this->renewalOrder(self::PAYER, ['isCouple' => false])));
    }

    public function testARenewalNamesThePartnerFrozenInItsMeta(): void
    {
        $order = $this->renewalOrder(self::PAYER, ['isCouple' => true, 'partnerBjUserId' => self::PARTNER]);

        $pair = $this->couples->forOrders([$order], [self::PAYER => 'PAYEUR Pascale', self::PARTNER => 'CONJOINT Marie'])[(int) $order['id']];

        self::assertSame(['name' => 'PAYEUR Pascale', 'bjUserId' => self::PAYER], $pair['payer']);
        self::assertSame(['name' => 'CONJOINT Marie', 'bjUserId' => self::PARTNER], $pair['partner']);
    }

    public function testARenewalWithoutAPartnerInItsMetaFallsBackToWhatFulfillmentRecorded(): void
    {
        // The shape the 2026-09 first-time-couple bug left before the backfill
        // repaired the meta: partner id 0 on the order, but a formula row.
        $order = $this->renewalOrder(self::PAYER, ['isCouple' => true, 'partnerBjUserId' => 0]);
        $this->formula(self::PAYER, true, self::PARTNER, (int) $order['id']);
        $this->formula(self::PARTNER, true, self::PAYER, (int) $order['id']);

        $pair = $this->couples->forOrder($order);

        self::assertSame(self::PARTNER, $pair['partner']['bjUserId']);
        self::assertSame('adhérent #' . self::PARTNER, $pair['partner']['name'], 'BJ unreachable: the id still identifies them');
    }

    public function testACoupleRenewalNamingNobodyIsReportedAsSuchRatherThanAsIndividual(): void
    {
        // Paid for two, renewed one: the pages must show that, not hide it.
        $order = $this->renewalOrder(self::PAYER, ['isCouple' => true, 'partnerBjUserId' => 0]);

        $pair = $this->couples->forOrder($order);

        self::assertNotNull($pair);
        self::assertNull($pair['partner']);
    }

    public function testAMemberIsPairedByBalleJauneFirst(): void
    {
        // A stale local row must not override what BJ says today.
        $this->formula(self::PAYER, true, self::OTHER, null);

        $partners = $this->couples->forMembers([
            ['user_id' => self::PAYER, 'lastname' => 'PAYEUR', 'firstname' => 'Pascale', 'custom2' => '1', 'custom3' => (string) self::PARTNER],
            ['user_id' => self::PARTNER, 'lastname' => 'CONJOINT', 'firstname' => 'Marie', 'custom2' => '1', 'custom3' => (string) self::PAYER],
        ]);

        self::assertSame(['name' => 'CONJOINT Marie', 'bjUserId' => self::PARTNER], $partners[self::PAYER]);
        self::assertSame(['name' => 'PAYEUR Pascale', 'bjUserId' => self::PAYER], $partners[self::PARTNER]);
    }

    public function testAMemberBalleJauneHasNotBeenWrittenForFallsBackToTheirLatestSeason(): void
    {
        $this->formula(self::PAYER, false, 0, null, self::SEASON - 1);
        $this->formula(self::PAYER, true, self::PARTNER, null, self::SEASON);
        $this->formula(self::OTHER, true, self::PARTNER, null, self::SEASON - 1);
        $this->formula(self::OTHER, false, 0, null, self::SEASON);

        $partners = $this->couples->forMembers([
            ['user_id' => self::PAYER, 'custom2' => ''],
            ['user_id' => self::OTHER, 'custom2' => ''],
        ]);

        self::assertSame(self::PARTNER, $partners[self::PAYER]['bjUserId']);
        self::assertArrayNotHasKey(self::OTHER, $partners, 'single in their latest season');
    }

    public function testAMemberFlaggedAsACoupleWithNobodyLinkedIsReportedAsSuch(): void
    {
        $partners = $this->couples->forMembers([['user_id' => self::PAYER, 'custom2' => '1', 'custom3' => '']]);

        self::assertArrayHasKey(self::PAYER, $partners);
        self::assertNull($partners[self::PAYER]);
    }

    public function testThePartnerSeesTheRenewalTheirPartnerPlacedForThem(): void
    {
        // Linked by meta alone (not fulfilled yet), and by formula alone.
        $pending = $this->renewalOrder(self::PAYER, ['isCouple' => true, 'partnerBjUserId' => self::PARTNER, 'lessons' => 0]);
        $fulfilled = $this->renewalOrder(self::PAYER, ['isCouple' => true, 'partnerBjUserId' => 0]);
        $this->formula(self::PARTNER, true, self::PAYER, (int) $fulfilled['id']);

        $ids = array_map('intval', array_column($this->orders->placedByPartnerFor(self::PARTNER), 'id'));
        sort($ids);

        self::assertSame([(int) $pending['id'], (int) $fulfilled['id']], $ids);
    }

    public function testPartnerOrdersNeverMatchAnotherMemberWhoseIdSharesADigitPrefix(): void
    {
        // 99999950 is a prefix of 999999501: the meta match must stop at the id.
        $this->renewalOrder(self::PAYER, ['isCouple' => true, 'partnerBjUserId' => self::PARTNER]);
        $this->renewalOrder(self::PARTNER, ['isCouple' => true, 'partnerBjUserId' => self::PAYER]);

        self::assertSame([], $this->orders->placedByPartnerFor(99999950));
        self::assertCount(1, $this->orders->placedByPartnerFor(self::PARTNER), 'their own order is not "placed by the partner"');
    }
}
