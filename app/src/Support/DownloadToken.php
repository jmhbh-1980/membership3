<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * The server half of assets/action-guard.js's download handling. A POST that
 * answers with a file never leaves the page, so the page learns the file has
 * arrived from a cookie set on that very response, carrying back the one-off
 * token the form posted. Readable by the page (not HttpOnly) and short-lived:
 * it says nothing but "this download has started".
 */
final class DownloadToken
{
    public const string FIELD = 'download_token';

    public static function confirm(Response $response, array $body): Response
    {
        $token = (string) ($body[self::FIELD] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return $response;
        }
        // Same Secure rule as the session cookie: on everywhere but local dev.
        $secure = session_get_cookie_params()['secure'] ? '; Secure' : '';
        return $response->withAddedHeader('Set-Cookie', self::FIELD . '=' . $token . '; Path=/; Max-Age=60; SameSite=Lax' . $secure);
    }
}
