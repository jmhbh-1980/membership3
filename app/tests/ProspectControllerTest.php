<?php

declare(strict_types=1);

namespace App\Tests;

use App\Controller\ProspectController;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProspectControllerTest extends TestCase
{
    public function testIsSummerPackApplicationMonthBoundaries(): void
    {
        self::assertFalse(ProspectController::isSummerPackApplication(new DateTimeImmutable('2026-06-30')));
        self::assertTrue(ProspectController::isSummerPackApplication(new DateTimeImmutable('2026-07-01')));
        self::assertTrue(ProspectController::isSummerPackApplication(new DateTimeImmutable('2026-08-31')));
        self::assertFalse(ProspectController::isSummerPackApplication(new DateTimeImmutable('2026-09-01')));
    }
}
