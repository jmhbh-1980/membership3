<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\DownloadToken;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * The cookie that tells a page its file download has started (see
 * assets/action-guard.js). Only a well-formed token is echoed back: whatever
 * else a form posts never ends up in a Set-Cookie header.
 */
final class DownloadTokenTest extends TestCase
{
    public function testEchoesAWellFormedTokenAsAShortLivedCookie(): void
    {
        $token = str_repeat('a1', 16);

        $cookie = DownloadToken::confirm(new Response(), ['download_token' => $token])->getHeaderLine('Set-Cookie');

        self::assertStringStartsWith('download_token=' . $token . '; Path=/; Max-Age=60; SameSite=Lax', $cookie);
        self::assertStringNotContainsString('HttpOnly', $cookie); // the page has to read it
    }

    public function testIgnoresAMissingOrMalformedToken(): void
    {
        foreach ([[], ['download_token' => ''], ['download_token' => 'abc; Path=/admin'], ['download_token' => str_repeat('g', 32)]] as $body) {
            self::assertFalse(DownloadToken::confirm(new Response(), $body)->hasHeader('Set-Cookie'));
        }
    }
}
