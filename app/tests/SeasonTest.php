<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\Season;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SeasonTest extends TestCase
{
    public function testFromDate(): void
    {
        self::assertSame(2025, Season::fromDate(new DateTimeImmutable('2025-09-01'))->startYear);
        self::assertSame(2025, Season::fromDate(new DateTimeImmutable('2026-08-31'))->startYear);
        self::assertSame(2024, Season::fromDate(new DateTimeImmutable('2025-08-31'))->startYear);
        self::assertSame('2025-2026', Season::fromDate(new DateTimeImmutable('2025-12-25'))->label());
    }

    public function testBounds(): void
    {
        $season = new Season(2025);
        self::assertSame('2025-09-01', $season->start()->format('Y-m-d'));
        self::assertSame('2026-08-31', $season->end()->format('Y-m-d'));
        self::assertSame('2025-09-15', $season->sept15()->format('Y-m-d'));
        self::assertTrue($season->contains(new DateTimeImmutable('2026-02-14')));
        self::assertFalse($season->contains(new DateTimeImmutable('2026-09-01')));
    }

    public function testLateSettlementStart(): void
    {
        $season = new Season(2025);
        self::assertSame('2026-07-01', $season->lateSettlementStart()->format('Y-m-d'));
    }

    public function testStartCappedAt(): void
    {
        $season = new Season(2026);
        self::assertSame('2026-08-20', $season->startCappedAt(new DateTimeImmutable('2026-08-20'))->format('Y-m-d'));
        self::assertSame('2026-09-01', $season->startCappedAt(new DateTimeImmutable('2026-09-01'))->format('Y-m-d'));
        self::assertSame('2026-09-01', $season->startCappedAt(new DateTimeImmutable('2027-01-15'))->format('Y-m-d'));
    }

    #[DataProvider('elapsedMonthsProvider')]
    public function testElapsedMonths(string $date, int $expected): void
    {
        self::assertSame($expected, (new Season(2025))->elapsedMonths(new DateTimeImmutable($date)));
    }

    public static function elapsedMonthsProvider(): array
    {
        return [
            'september = 0'          => ['2025-09-20', 0],
            'october = 1'            => ['2025-10-01', 1],
            'any day of november = 2' => ['2025-11-30', 2],
            'february = 5'           => ['2026-02-10', 5],
            'august capped at 11'    => ['2026-08-31', 11],
        ];
    }
}
