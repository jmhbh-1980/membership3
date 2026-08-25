<?php

declare(strict_types=1);

namespace App\Service;

/**
 * BJ has no structured field for a minor's legal guardian contact — it's
 * encoded as one string in custom1, "fullname · email · phone" (255 chars
 * max, BJ's own field limit). format()/parse() keep both directions of that
 * encoding in one place, used by the join wizard's fulfillment (write-only)
 * and the renewal guardian step (both, since it also has to prefill from
 * whatever is already stored).
 */
final class GuardianContact
{
    private const string SEPARATOR = ' · ';

    public static function format(string $fullname, string $email, string $phone): string
    {
        $parts = array_filter([trim($fullname), trim($email), trim($phone)], fn (string $p): bool => $p !== '');
        return mb_substr(implode(self::SEPARATOR, $parts), 0, 255);
    }

    /**
     * Defensive: a value set before this structured form existed (or edited
     * by hand in BJ) may not contain the separator at all — in that case the
     * whole string lands in fullname rather than being discarded.
     *
     * @return array{fullname: string, email: string, phone: string}
     */
    public static function parse(string $contact): array
    {
        $parts = explode(self::SEPARATOR, $contact);
        return [
            'fullname' => trim($parts[0] ?? ''),
            'email'    => trim($parts[1] ?? ''),
            'phone'    => trim($parts[2] ?? ''),
        ];
    }
}
