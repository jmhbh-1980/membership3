<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\InstallmentPlanRepository;
use App\Support\Db;
use PHPUnit\Framework\TestCase;

/**
 * Integration test against the real dev DB (see CLAUDE.md) — no mocking,
 * matching this app's convention for repository tests.
 */
final class InstallmentPlanRepositoryTest extends TestCase
{
    private const int TEST_BJ_USER_ID = 900001;

    private InstallmentPlanRepository $repo;
    private Db $db;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->repo = new InstallmentPlanRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->pdo()->prepare('DELETE FROM installment_plans WHERE bj_user_id = ?')->execute([self::TEST_BJ_USER_ID]);
    }

    private function sampleSchedule(): array
    {
        return [
            ['number' => 2, 'amount' => 99.50, 'lines' => [], 'due_date' => '2026-10-07', 'extends_to' => '2027-09-15', 'status' => 'pending'],
        ];
    }

    public function testCreateThenFindById(): void
    {
        $plan = $this->repo->create(self::TEST_BJ_USER_ID, 2026, 2, ['subscriptionType' => 'heures-pleines'], $this->sampleSchedule(), 'cust-1');

        self::assertSame(self::TEST_BJ_USER_ID, (int) $plan['bj_user_id']);
        self::assertSame(2026, (int) $plan['season_start_year']);
        self::assertSame(2, (int) $plan['installment_count']);
        self::assertSame('active', $plan['status']);
        self::assertSame('cust-1', $plan['sumup_customer_id']);
        self::assertSame('', $plan['sumup_payment_token']);

        $found = $this->repo->findById((int) $plan['id']);
        self::assertNotNull($found);
        self::assertSame($plan['id'], $found['id']);
    }

    public function testActiveForFindsOnlyActiveStatusForThatSeason(): void
    {
        $plan = $this->repo->create(self::TEST_BJ_USER_ID, 2026, 3, [], $this->sampleSchedule(), 'cust-2');

        self::assertNotNull($this->repo->activeFor(self::TEST_BJ_USER_ID, 2026));
        self::assertNull($this->repo->activeFor(self::TEST_BJ_USER_ID, 2099));

        $this->repo->markStatus((int) $plan['id'], 'completed');
        self::assertNull($this->repo->activeFor(self::TEST_BJ_USER_ID, 2026));
    }

    public function testSetTokenPersists(): void
    {
        $plan = $this->repo->create(self::TEST_BJ_USER_ID, 2026, 2, [], $this->sampleSchedule(), 'cust-3');
        $this->repo->setToken((int) $plan['id'], 'tok-abc');

        $found = $this->repo->findById((int) $plan['id']);
        self::assertSame('tok-abc', $found['sumup_payment_token']);
    }

    public function testUpdateScheduleRoundTripsJson(): void
    {
        $plan = $this->repo->create(self::TEST_BJ_USER_ID, 2026, 2, [], $this->sampleSchedule(), 'cust-4');
        $newSchedule = $this->sampleSchedule();
        $newSchedule[0]['status'] = 'charged';
        $this->repo->updateSchedule((int) $plan['id'], $newSchedule);

        $found = $this->repo->findById((int) $plan['id']);
        $decoded = json_decode((string) $found['schedule'], true);
        self::assertSame('charged', $decoded[0]['status']);
    }

    public function testAllActiveIncludesOnlyActivePlans(): void
    {
        $plan = $this->repo->create(self::TEST_BJ_USER_ID, 2026, 2, [], $this->sampleSchedule(), 'cust-5');
        $ids = array_column($this->repo->allActive(), 'id');
        self::assertContains($plan['id'], $ids);

        $this->repo->markStatus((int) $plan['id'], 'lapsed');
        $ids = array_column($this->repo->allActive(), 'id');
        self::assertNotContains($plan['id'], $ids);
    }
}
