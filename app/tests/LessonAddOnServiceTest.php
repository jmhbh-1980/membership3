<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\LessonAddOnService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LessonAddOnServiceTest extends TestCase
{
    public function testTargetSeasonBoundary(): void
    {
        // Up to and including 30 June: still the running season.
        self::assertSame(2025, LessonAddOnService::targetSeason(new DateTimeImmutable('2026-06-30'))->startYear);
        // From 1 July: no lessons run in July/August, so it's next season instead.
        self::assertSame(2026, LessonAddOnService::targetSeason(new DateTimeImmutable('2026-07-01'))->startYear);
        self::assertSame(2026, LessonAddOnService::targetSeason(new DateTimeImmutable('2026-08-31'))->startYear);
        // Back into the new season proper.
        self::assertSame(2026, LessonAddOnService::targetSeason(new DateTimeImmutable('2026-09-01'))->startYear);
    }
}
