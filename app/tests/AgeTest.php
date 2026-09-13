<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\Age;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AgeTest extends TestCase
{
    private const TODAY = '2026-09-12';

    private function on(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY);
    }

    public function testCompletedYears(): void
    {
        self::assertSame(28, Age::years('1998-03-12', $this->on()));
    }

    public function testBirthdayNotYetReachedThisYearCountsOneLess(): void
    {
        self::assertSame(27, Age::years('1998-12-31', $this->on()));
    }

    public function testBirthdayToday(): void
    {
        self::assertSame(28, Age::years('1998-09-12', $this->on()));
    }

    public function testBirthdayTomorrow(): void
    {
        self::assertSame(27, Age::years('1998-09-13', $this->on()));
    }

    /** 29 February: PHP counts the year as complete on 1 March of a non-leap year. */
    public function testLeapDayBirthdate(): void
    {
        self::assertSame(18, Age::years('2008-02-29', new DateTimeImmutable('2026-03-01')));
        self::assertSame(17, Age::years('2008-02-29', new DateTimeImmutable('2026-02-28')));
    }

    /** Balle Jaune's answer for a member whose birthday was never filled in. */
    public function testMySqlZeroDateHasNoAge(): void
    {
        self::assertNull(Age::years('0000-00-00', $this->on()));
        self::assertNull(Age::years('0000-00-00 00:00:00', $this->on()));
    }

    public function testMissingDateHasNoAge(): void
    {
        self::assertNull(Age::years(null, $this->on()));
        self::assertNull(Age::years('', $this->on()));
        self::assertNull(Age::years('   ', $this->on()));
    }

    public function testMalformedDateHasNoAge(): void
    {
        self::assertNull(Age::years('pas une date', $this->on()));
    }

    public function testFutureBirthdateHasNoAge(): void
    {
        self::assertNull(Age::years('2035-01-01', $this->on()));
    }

    public function testSuffixIsWhatFollowsAFormattedDate(): void
    {
        self::assertSame(' (28 ans)', Age::suffix('1998-03-12', $this->on()));
    }

    public function testSuffixSingularBelowTwo(): void
    {
        self::assertSame(' (1 an)', Age::suffix('2025-01-01', $this->on()));
        self::assertSame(' (0 an)', Age::suffix('2026-01-01', $this->on()));
    }

    public function testSuffixIsEmptyWithoutAUsableDate(): void
    {
        self::assertSame('', Age::suffix('0000-00-00', $this->on()));
        self::assertSame('', Age::suffix(null, $this->on()));
    }

    public function testDefaultsToToday(): void
    {
        $born = (new DateTimeImmutable('today'))->modify('-30 years');
        self::assertSame(30, Age::years($born->format('Y-m-d')));
    }
}
