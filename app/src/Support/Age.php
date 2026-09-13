<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use Exception;

/**
 * Age in completed years, for showing next to a birth date.
 *
 * Birth dates reach the screens from two places with two shapes of "missing":
 * Balle Jaune answers with the MySQL zero date ('0000-00-00') or an empty
 * string for a member whose birthday was never filled in, and an application's
 * own birthdate column is always set. Both are handled here rather than at each
 * call site, so a missing date shows the date column's own em dash and simply
 * no age, never "(56 ans)" computed from year zero.
 *
 * Deliberately today's age, not the age at season start: the reader is a person
 * looking at a screen now — the age that matters to pricing is PricingService's
 * business, and it asks its own question (isJeune at season start, isMinor
 * today) off the same birth date.
 */
final class Age
{
    /** Completed years, or null when the birth date is missing, malformed, or in the future. */
    public static function years(?string $birthdate, ?DateTimeImmutable $on = null): ?int
    {
        if ($birthdate === null || trim($birthdate) === '' || str_starts_with($birthdate, '0000')) {
            return null;
        }

        try {
            $born = new DateTimeImmutable($birthdate);
        } catch (Exception) {
            return null;
        }

        $on ??= new DateTimeImmutable('today');
        if ($born > $on) {
            // A birth date after today is a typo (a 2035 in a date field), and
            // "(-10 ans)" would dress it up as a fact instead of hiding it.
            return null;
        }

        return $born->diff($on)->y;
    }

    /**
     * The " (27 ans)" that follows an already-formatted birth date, empty when
     * there is no usable date — so a template can append it unconditionally.
     */
    public static function suffix(?string $birthdate, ?DateTimeImmutable $on = null): string
    {
        $years = self::years($birthdate, $on);

        return $years === null ? '' : ' (' . $years . ' ' . ($years > 1 ? 'ans' : 'an') . ')';
    }
}
