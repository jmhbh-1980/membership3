<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\InvoiceNumberService;
use App\Support\Db;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: allocate() reads/writes invoice_counters via a real Db
 * connection (dev MySQL, see CLAUDE.md). A season label far in the future is
 * used so these rows never collide with real invoicing data; tearDown()
 * removes them.
 */
final class InvoiceNumberServiceTest extends TestCase
{
    private const string FAKE_SEASON = '2999-3000';

    private InvoiceNumberService $numbers;
    private Db $db;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->numbers = new InvoiceNumberService($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->pdo()->prepare('DELETE FROM invoice_counters WHERE season_label = ?')->execute([self::FAKE_SEASON]);
    }

    public function testSeasonLabelForAug1Boundary(): void
    {
        self::assertSame('2025-2026', $this->numbers->seasonLabelFor(new DateTimeImmutable('2026-07-31')));
        self::assertSame('2026-2027', $this->numbers->seasonLabelFor(new DateTimeImmutable('2026-08-01')));
        self::assertSame('2026-2027', $this->numbers->seasonLabelFor(new DateTimeImmutable('2027-01-15')));
    }

    public function testAllocateFormatsAndIncrementsSequentially(): void
    {
        // A date whose computed season label is FAKE_SEASON, so this never
        // touches a counter row any real invoice could use.
        $withinFakeSeason = new DateTimeImmutable('2999-08-15');

        $first = $this->numbers->allocate($withinFakeSeason);
        $second = $this->numbers->allocate($withinFakeSeason);

        self::assertSame(self::FAKE_SEASON, $first['seasonLabel']);
        self::assertSame($first['seasonLabel'], $second['seasonLabel']);
        self::assertSame($first['sequence'] + 1, $second['sequence']);
        self::assertSame(sprintf('SQ-%s-%03d', $first['seasonLabel'], $first['sequence']), $first['number']);
    }
}
