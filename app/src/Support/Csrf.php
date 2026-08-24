<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Session-based CSRF tokens. Call token() when rendering a form and
 * validate() on every POST before doing anything else.
 */
final class Csrf
{
    private const string SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION[self::SESSION_KEY])
            && hash_equals($_SESSION[self::SESSION_KEY], $token);
    }
}
