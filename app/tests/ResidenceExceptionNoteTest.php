<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\FulfillmentService;
use App\Service\PricingService;
use PHPUnit\Framework\TestCase;

/**
 * The line fulfillment adds to a member's Balle Jaune subscription_notes when
 * an order was charged under a residence exception. Joins, renewals and
 * installments all build it through this one helper, so pinning it here pins
 * what every path writes to BJ. Pure — no DB, no BJ.
 */
final class ResidenceExceptionNoteTest extends TestCase
{
    private const string G = PricingService::RESIDENCE_GARENNOIS;
    private const string H = PricingService::RESIDENCE_HORS_COMMUNE;

    public function testNothingIsWrittenWithoutAnException(): void
    {
        self::assertSame('', FulfillmentService::residenceExceptionNote(self::H, self::H, 'ignored'));
        self::assertSame('', FulfillmentService::residenceExceptionNote(self::G, self::G, ''));
    }

    public function testOrdersFromBeforeResidenceWasRecordedWriteNothing(): void
    {
        // Pre-0022 orders have empty residence columns; fulfillment's fallbacks
        // then yield '' (or the same value twice) and there is nothing to claim.
        self::assertSame('', FulfillmentService::residenceExceptionNote('', '', ''));
        self::assertSame('', FulfillmentService::residenceExceptionNote('', self::G, 'x'));
    }

    public function testTheGarennoisFavourIsSpelledOutWithItsReason(): void
    {
        self::assertSame(
            'Tarif Garennois accordé à titre exceptionnel (résidence : Hors commune) — motif : Bénévole encadrant les jeunes',
            FulfillmentService::residenceExceptionNote(self::H, self::G, 'Bénévole encadrant les jeunes'),
        );
    }

    public function testTheReverseDirectionReadsCorrectlyToo(): void
    {
        self::assertSame(
            'Tarif Hors commune accordé à titre exceptionnel (résidence : Garennois)',
            FulfillmentService::residenceExceptionNote(self::G, self::H, ''),
        );
    }

    public function testTheReasonIsFlattenedAndCappedSoItCannotEatTheHistory(): void
    {
        $note = FulfillmentService::residenceExceptionNote(self::H, self::G, "Ligne un\n\n   ligne deux " . str_repeat('x', 400));

        self::assertStringContainsString('motif : Ligne un ligne deux ', $note);
        self::assertStringNotContainsString("\n", $note);
        // Fixed prefix + at most 150 characters of reason, whatever was typed.
        $prefix = 'Tarif Garennois accordé à titre exceptionnel (résidence : Hors commune) — motif : ';
        self::assertSame(mb_strlen($prefix) + 150, mb_strlen($note));
    }
}
