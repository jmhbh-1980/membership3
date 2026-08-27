<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\WhatsApp;
use PHPUnit\Framework\TestCase;

final class WhatsAppTest extends TestCase
{
    public function testFrenchLocalFormat(): void
    {
        self::assertSame('https://wa.me/33601020304', WhatsApp::link('0601020304'));
    }

    public function testAlreadyInternationalWithoutPlus(): void
    {
        self::assertSame('https://wa.me/33756900235', WhatsApp::link('33756900235'));
    }

    public function testStripsSpacesDotsAndDashes(): void
    {
        self::assertSame('https://wa.me/33601020304', WhatsApp::link('06 01.02-03 04'));
    }

    public function testStripsLeadingPlus(): void
    {
        self::assertSame('https://wa.me/33601020304', WhatsApp::link('+33601020304'));
    }

    public function testEmptyStringReturnsNull(): void
    {
        self::assertNull(WhatsApp::link(''));
    }

    public function testNonFrenchShapedDigitsPassThroughBestEffort(): void
    {
        self::assertSame('https://wa.me/123', WhatsApp::link('abc123'));
    }
}
