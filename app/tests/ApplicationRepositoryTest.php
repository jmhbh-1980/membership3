<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\ApplicationRepository;
use App\Support\Db;
use PHPUnit\Framework\TestCase;

/**
 * Integration test against the dev MySQL, same convention as
 * RenewalServiceTest. Covers approvedAwaitingPayment(), which backs the
 * "Approuvées, en attente de paiement" queue — the gap between the review
 * queue and a settled order, where validating an application used to make it
 * disappear from every admin screen.
 *
 * Rows are created under a throwaway email prefix and removed in tearDown().
 */
final class ApplicationRepositoryTest extends TestCase
{
    private const string EMAIL_PREFIX = 'apprepo-test-';
    private const int SEASON = 2999;

    private ApplicationRepository $applications;
    private Db $db;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->applications = new ApplicationRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->pdo()
            ->prepare('DELETE FROM applications WHERE email LIKE ?')
            ->execute([self::EMAIL_PREFIX . '%']);
    }

    private function make(string $status, ?string $validatedAt = null): int
    {
        $app = $this->applications->create(self::EMAIL_PREFIX . bin2hex(random_bytes(4)) . '@example.org', self::SEASON);
        $this->applications->update((int) $app['id'], ['status' => $status, 'validated_at' => $validatedAt]);
        return (int) $app['id'];
    }

    /** @return int[] */
    private function idsAwaitingPayment(): array
    {
        // array_values: array_filter preserves keys, and these assertions are
        // about order, so the list has to be re-indexed to compare positionally.
        return array_values(array_map(
            static fn (array $row): int => (int) $row['id'],
            array_filter(
                $this->applications->approvedAwaitingPayment(),
                static fn (array $row): bool => str_starts_with((string) $row['email'], self::EMAIL_PREFIX),
            ),
        ));
    }

    public function testPicksUpBothApprovedButUnpaidStatuses(): void
    {
        $validated = $this->make('validated', '2999-09-01 10:00:00');
        $awaiting = $this->make('awaiting_payment', '2999-09-02 10:00:00');

        $ids = $this->idsAwaitingPayment();

        self::assertContains($validated, $ids, "'validated' — approved, payment link never opened");
        self::assertContains($awaiting, $ids, "'awaiting_payment' — checkout started, never settled");
    }

    public function testExcludesEveryOtherStatus(): void
    {
        // Neither end of the funnel belongs here: the first three are not yet
        // approved, the last three are already settled or closed.
        foreach (['draft', 'submitted', 'rejected', 'paid', 'fulfilled'] as $status) {
            $this->make($status, '2999-09-01 10:00:00');
        }

        self::assertSame([], $this->idsAwaitingPayment());
    }

    public function testOrdersByHowLongTheyHaveBeenWaiting(): void
    {
        // Deliberately created newest-first, so a passing assertion can only
        // come from the ORDER BY rather than insertion order.
        $recent = $this->make('validated', '2999-09-10 10:00:00');
        $oldest = $this->make('validated', '2999-09-01 10:00:00');
        $middle = $this->make('awaiting_payment', '2999-09-05 10:00:00');

        self::assertSame([$oldest, $middle, $recent], $this->idsAwaitingPayment());
    }
}
