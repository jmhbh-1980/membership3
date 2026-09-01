<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * A club season: 1 September (startYear) → 31 August (startYear+1).
 * Coverage of a season is decided against a fixed marker, this season's own
 * sept15() — see RenewalService::subscriptionCovers() — not the calendar
 * date it's checked on. A completed join/renewal for this season writes
 * Balle Jaune's subscription_date_end as $this->next()->sept15() (see
 * FulfillmentService), which is exactly the next season's own marker: it
 * clears this season's coverage but not (yet) the next one's.
 */
final readonly class Season
{
    public function __construct(public int $startYear)
    {
    }

    public static function fromDate(DateTimeImmutable $date): self
    {
        $year = (int) $date->format('Y');
        return new self((int) $date->format('n') >= 9 ? $year : $year - 1);
    }

    public function label(): string
    {
        return $this->startYear . '-' . ($this->startYear + 1);
    }

    public function start(): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%d-09-01', $this->startYear));
    }

    public function end(): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%d-08-31', $this->startYear + 1));
    }

    /** 15 September of this season's own start year. */
    public function sept15(): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%d-09-15', $this->startYear));
    }

    /**
     * 1 July of this season's second calendar year — on or after this date,
     * a mid-season join/renewal is priced as the flat "Pack été"
     * late-settlement fee instead of prorated (see RenewalService::
     * renewalTarget()). Anchored to *this* season, not a bare calendar
     * month: July/August genuinely means "near the end of this season,"
     * never the first four months (Sept-Dec) of a season that just started.
     */
    public function lateSettlementStart(): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%d-07-01', $this->startYear + 1));
    }

    /** Never returns a date after $today — caps a season's nominal start to "now" for records with no prior start date. */
    public function startCappedAt(DateTimeImmutable $today): DateTimeImmutable
    {
        return $today < $this->start() ? $today : $this->start();
    }

    public function next(): self
    {
        return new self($this->startYear + 1);
    }

    public function contains(DateTimeImmutable $date): bool
    {
        return $date >= $this->start() && $date <= $this->end();
    }

    /**
     * Complete months elapsed since the season start (0 in September,
     * 1 in October, 2 in November, …, 11 in August). Used for the
     * mid-season prorata discount.
     */
    public function elapsedMonths(DateTimeImmutable $date): int
    {
        $months = ((int) $date->format('Y') * 12 + (int) $date->format('n'))
            - ($this->startYear * 12 + 9);

        return max(0, min(11, $months));
    }
}
