<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Formats a stored phone number as a WhatsApp click-to-chat link
 * (https://wa.me/<digits>). Stored numbers have never been format-validated
 * (ProspectController only checks non-empty) — real data mixes French local
 * (0X XX XX XX XX) and already-international shapes, so this normalizes the
 * local form and otherwise passes the digits through as-is: best effort,
 * not a validity guarantee.
 */
final class WhatsApp
{
    public static function link(string $rawPhone): ?string
    {
        $digits = preg_replace('/\D/', '', $rawPhone);
        if ($digits === '' || $digits === null) {
            return null;
        }

        if (strlen($digits) === 10 && $digits[0] === '0') {
            $digits = '33' . substr($digits, 1);
        }

        return 'https://wa.me/' . $digits;
    }
}
