<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\ApplicationRepository;
use App\Repository\OrderRepository;
use App\Service\PendingDecisionsService;
use App\Service\PricingService;
use App\Service\RenewalService;
use App\Support\Db;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Integration test against the dev MySQL, same convention as
 * RenewalServiceTest. The behaviour worth pinning is the cross-type ordering:
 * five separate admin pages each sort their own queue, and the only thing this
 * board adds is answering "who has waited longest" across all of them.
 *
 * Balle Jaune is never reached: every fixture order is a join order, whose name
 * resolves locally through application_people.
 */
final class PendingDecisionsServiceTest extends TestCase
{
    private const int SEASON = 2999;
    private const int FAKE_BJ_USER_ID = 999999301;
    private const string EMAIL_PREFIX = 'pending-decisions-test-';

    private PendingDecisionsService $service;
    private ApplicationRepository $applications;
    private OrderRepository $orders;
    private Db $db;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->applications = new ApplicationRepository($this->db);
        $this->orders = new OrderRepository($this->db);
        $pricing = new PricingService(dirname(__DIR__, 2) . '/pricing_data');
        $logger = new \App\Support\Logger(sys_get_temp_dir() . '/pending_decisions_test.log');

        $this->service = new PendingDecisionsService(
            $this->applications,
            $this->orders,
            new RenewalService($this->db, $pricing),
            // Empty base URL: a fixture that reached BJ would fail loudly here
            // rather than quietly hitting the network.
            new \App\Service\BalleJaune\BalleJauneClient('', '', $logger),
            $this->db,
        );

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
        $pdo->prepare('DELETE FROM change_requests WHERE bj_user_id = ?')->execute([self::FAKE_BJ_USER_ID]);
    }

    private function ageDays(int $days): string
    {
        return (new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');
    }

    /** A submitted application awaiting review, aged $days. */
    private function application(int $days, string $lastname): int
    {
        $app = $this->applications->create(self::EMAIL_PREFIX . bin2hex(random_bytes(4)) . '@example.org', self::SEASON);
        $id = (int) $app['id'];
        $this->applications->savePerson($id, 1, [
            'firstname' => 'Test', 'lastname' => $lastname, 'birthdate' => '1990-01-01',
        ]);
        $this->applications->update($id, ['status' => 'submitted', 'subscription_type' => 'heures-pleines']);
        $this->db->pdo()->prepare('UPDATE applications SET submitted_at = ? WHERE id = ?')
            ->execute([$this->ageDays($days), $id]);
        return $id;
    }

    /** A join order parked in one of the three approval holds, aged $days. */
    private function heldOrder(string $status, int $days, string $lastname): int
    {
        $applicationId = $this->application(999, $lastname);
        // Not itself awaiting review — only the order should show up.
        $this->applications->update($applicationId, ['status' => 'awaiting_payment']);

        $order = $this->orders->create('join', $applicationId, 0, self::EMAIL_PREFIX . 'order@example.org', 100.0, []);
        $id = (int) $order['id'];
        $this->db->pdo()->prepare('UPDATE orders SET status = ?, created_at = ? WHERE id = ?')
            ->execute([$status, $this->ageDays($days), $id]);
        return $id;
    }

    /** @return list<array> only the rows this test created */
    private function ownRows(): array
    {
        $mine = ['DOYEN', 'MILIEU', 'CADET', 'PROMO', 'ETUDIANT', 'VIREMENT'];
        return array_values(array_filter(
            $this->service->all(),
            static fn (array $r): bool => in_array(explode(' ', $r['who'])[0], $mine, true),
        ));
    }

    public function testOrdersEveryTypeIntoOneQueueOldestFirst(): void
    {
        // Created newest-first and interleaved across types, so a passing
        // assertion can only come from the sort, not from insertion order.
        $this->heldOrder('awaiting_promo_approval', 3, 'PROMO');
        $this->application(10, 'MILIEU');
        $this->heldOrder('awaiting_bank_transfer', 1, 'VIREMENT');
        $this->application(40, 'DOYEN');
        $this->heldOrder('awaiting_student_approval', 25, 'ETUDIANT');

        $rows = $this->ownRows();

        self::assertSame(
            ['DOYEN', 'ETUDIANT', 'MILIEU', 'PROMO', 'VIREMENT'],
            array_map(static fn (array $r): string => explode(' ', $r['who'])[0], $rows),
        );
        self::assertSame(
            ['application', 'student', 'application', 'promo', 'bank_transfer'],
            array_column($rows, 'type'),
        );
    }

    public function testReportsHowLongEachHasWaited(): void
    {
        $this->application(40, 'DOYEN');

        $rows = $this->ownRows();

        self::assertCount(1, $rows);
        self::assertSame(40, $rows[0]['days']);
        self::assertTrue($rows[0]['blocking']);
    }

    public function testDeepLinksAnApplicationToItsOwnReviewPage(): void
    {
        // The application is the one type whose decision needs a specific row —
        // the other four are decided from a single per-type page.
        $id = $this->application(5, 'CADET');

        $rows = $this->ownRows();

        self::assertSame('/admin/demandes/' . $id, $rows[0]['url']);
    }

    public function testIgnoresSettledAndUnsubmittedWork(): void
    {
        // Neither end of the funnel is a pending decision: a draft was never
        // submitted, and a fulfilled order was decided long ago.
        $draft = $this->application(5, 'DOYEN');
        $this->applications->update($draft, ['status' => 'draft']);

        $settled = $this->heldOrder('awaiting_promo_approval', 5, 'PROMO');
        $this->db->pdo()->prepare('UPDATE orders SET status = "fulfilled" WHERE id = ?')->execute([$settled]);

        self::assertSame([], $this->ownRows());
    }

    public function testCountsByType(): void
    {
        $this->application(5, 'DOYEN');
        $this->application(6, 'MILIEU');
        $this->heldOrder('awaiting_bank_transfer', 7, 'VIREMENT');

        $counts = $this->service->countsByType($this->ownRows());

        self::assertSame(2, $counts['application']);
        self::assertSame(1, $counts['bank_transfer']);
        self::assertArrayNotHasKey('promo', $counts);
    }
}
