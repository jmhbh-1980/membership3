<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\ApplicationRepository;
use App\Repository\OrderRepository;
use App\Support\Db;
use PHPUnit\Framework\TestCase;

/**
 * The two queries the member dossier is built on, both of which have to bridge
 * the join/renewal identity split: a renewal, credits or lessons order carries
 * bj_user_id directly, while a join order's own bj_user_id is 0 — the BJ account
 * doesn't exist when it is created — so it is only reachable through
 * application_people, which fulfillment writes the BJ id onto afterwards.
 *
 * Getting that wrong silently hides a member's entire join history, which is
 * exactly the half a dossier most needs, so it is worth pinning directly.
 *
 * Integration test against dev MySQL, same convention as RenewalServiceTest.
 */
final class MemberHistoryQueriesTest extends TestCase
{
    private const int SEASON = 2999;
    private const int MEMBER = 999999401;
    private const int OTHER_MEMBER = 999999402;
    private const string EMAIL_PREFIX = 'member-history-test-';

    private ApplicationRepository $applications;
    private OrderRepository $orders;
    private Db $db;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->applications = new ApplicationRepository($this->db);
        $this->orders = new OrderRepository($this->db);
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
    }

    /** An application whose person at $position has been linked to $bjUserId by fulfillment. */
    private function application(int $bjUserId, int $position = 1): int
    {
        $app = $this->applications->create(self::EMAIL_PREFIX . bin2hex(random_bytes(4)) . '@example.org', self::SEASON);
        $id = (int) $app['id'];
        $this->applications->savePerson($id, $position, ['firstname' => 'Test', 'lastname' => 'MEMBRE', 'birthdate' => '1990-01-01']);
        $this->applications->setPersonBjUserId($id, $position, $bjUserId);
        return $id;
    }

    private function order(string $kind, ?int $applicationId, int $bjUserId): int
    {
        $order = $this->orders->create(
            $kind,
            $applicationId,
            $bjUserId,
            self::EMAIL_PREFIX . 'order@example.org',
            100.0,
            [],
        );
        return (int) $order['id'];
    }

    public function testFindsJoinOrdersThroughTheApplicationAndRenewalsDirectly(): void
    {
        // A join order's own bj_user_id is 0 — the account didn't exist yet.
        $applicationId = $this->application(self::MEMBER);
        $joinOrder = $this->order('join', $applicationId, 0);
        $renewalOrder = $this->order('renewal', null, self::MEMBER);

        $ids = array_map('intval', array_column($this->orders->allForBjUser(self::MEMBER), 'id'));

        self::assertContains($joinOrder, $ids, 'a join order is only reachable via application_people');
        self::assertContains($renewalOrder, $ids);
    }

    public function testDoesNotLeakAnotherMembersOrders(): void
    {
        $this->order('renewal', null, self::OTHER_MEMBER);
        $otherApplication = $this->application(self::OTHER_MEMBER);
        $this->order('join', $otherApplication, 0);

        self::assertSame([], $this->orders->allForBjUser(self::MEMBER));
    }

    public function testFindsTheJoinOrderForThePartnerOnACoupleRegistration(): void
    {
        // Position 2 of a couple is a real member of the club with a real join
        // order, even though the order was paid by their partner.
        $applicationId = $this->application(self::MEMBER, position: 2);
        $joinOrder = $this->order('join', $applicationId, 0);

        $ids = array_map('intval', array_column($this->orders->allForBjUser(self::MEMBER), 'id'));

        self::assertSame([$joinOrder], $ids);
    }

    public function testReturnsEachOrderOnceEvenWhenBothLinkagesCouldMatch(): void
    {
        // A renewal order that also carries an application_id would otherwise
        // satisfy both halves of the UNION.
        $applicationId = $this->application(self::MEMBER);
        $order = $this->order('renewal', $applicationId, self::MEMBER);

        $ids = array_map('intval', array_column($this->orders->allForBjUser(self::MEMBER), 'id'));

        self::assertSame([$order], $ids);
    }

    public function testApplicationsAreFoundForApplicantAndPartnerAlike(): void
    {
        $asApplicant = $this->application(self::MEMBER, position: 1);
        $asPartner = $this->application(self::MEMBER, position: 2);
        $this->application(self::OTHER_MEMBER);

        $ids = array_map('intval', array_column($this->applications->findByBjUserId(self::MEMBER), 'id'));
        sort($ids);

        self::assertSame([$asApplicant, $asPartner], $ids);
    }

    public function testAMemberWhoNeverJoinedThroughTheAppHasNoApplication(): void
    {
        // The case that decides whether a dossier can ever show a photo or a
        // justificatif — most existing members are in it.
        self::assertSame([], $this->applications->findByBjUserId(self::MEMBER));
    }
}
