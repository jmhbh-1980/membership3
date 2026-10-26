<?php

declare(strict_types=1);

namespace App\Tests;

use App\Controller\RenewalController;
use App\Service\CartLine;
use App\Service\FulfillmentService;
use App\Service\PricingService;
use App\Service\Quote;
use App\Service\Season;
use PHPUnit\Framework\TestCase;

/**
 * A member on the Formule Tickets joining the yearly membership (club
 * decision, October 2026): the 1ère inscription rate, minus their unused
 * tickets at this season's price per ticket, those tickets then taken off
 * their Balle Jaune balance once the payment has gone through.
 *
 * Pure: the pricing file is read, nothing else.
 */
final class TicketUpgradeTest extends TestCase
{
    private static function quote(): Quote
    {
        return new Quote([
            new CartLine('cotisation', 'Cotisation — Heures Pleines (1ère inscription)', 365.0, 365.0),
            new CartLine('licence', 'Licence Pass', 20.0, 20.0),
        ], 0, 'heures-pleines', '_Abonnement Individuel - Heures Pleines');
    }

    private static function intent(float $ticketsLeft, float $unitPrice = 2.0, bool $upgrade = true): array
    {
        return ['ticketUpgrade' => $upgrade, 'ticketsLeft' => $ticketsLeft, 'ticketUnitPrice' => $unitPrice];
    }

    public function testATicketMemberIsRecognisedByTheirBalleJauneSubscription(): void
    {
        self::assertTrue(PricingService::isTicketSubscription('FORMULE TICKETS-5'));
        self::assertTrue(PricingService::isTicketSubscription('Formule tickets 10'));
        self::assertFalse(PricingService::isTicketSubscription('_Abonnement Individuel - Heures Pleines'));
        self::assertFalse(PricingService::isTicketSubscription(''));
    }

    public function testOneTicketCostsThePackPriceOverItsTickets(): void
    {
        $pricing = new PricingService(dirname(__DIR__, 2) . '/pricing_data');
        $pack = $pricing->ticketPack(new Season(2026));

        self::assertSame(round($pack['price'] / $pack['tickets'], 2), $pricing->ticketUnitPrice(new Season(2026)));
    }

    public function testUnusedTicketsBecomeACreditLineOnTheMembership(): void
    {
        $quote = RenewalController::withTicketCredit(self::quote(), self::intent(7));

        $credit = $quote->lines[2];
        self::assertSame(['credit', -14.0, -14.0], [$credit->type, $credit->amount, $credit->baseAmount]);
        self::assertSame('Reprise de vos crédits de jeu (7 × 2,00 €)', $credit->label);
        self::assertSame(371.0, $quote->total());
        // Not a 'discount': that type is what an order records as its promo code / student discount.
        self::assertSame([], array_filter($quote->lines, fn (CartLine $l) => $l->type === 'discount'));
    }

    public function testNoCreditWithoutTicketsOrOutsideAnUpgrade(): void
    {
        foreach ([self::intent(0), self::intent(5, 0.0), self::intent(5, 2.0, false), []] as $intent) {
            self::assertCount(2, RenewalController::withTicketCredit(self::quote(), $intent)->lines);
        }
    }

    public function testHalfCreditsAreShownAsSuch(): void
    {
        self::assertSame('2,5', RenewalController::ticketCount(2.5));
        self::assertSame('5', RenewalController::ticketCount(5.0));
        self::assertSame('Reprise de vos crédits de jeu (2,5 × 2,00 €)', RenewalController::withTicketCredit(self::quote(), self::intent(2.5))->lines[2]->label);
    }

    public function testExactlyTheCreditedTicketsAreTakenOffTheBalance(): void
    {
        self::assertSame([0.0, 0.0], FulfillmentService::ticketsAfterUpgrade(7.0, 7.0));
        // Credits bought after the cart was priced stay.
        self::assertSame([5.0, 0.0], FulfillmentService::ticketsAfterUpgrade(12.0, 7.0));
        // Tickets played meanwhile: the balance stops at 0 and the shortfall is reported.
        self::assertSame([0.0, 3.0], FulfillmentService::ticketsAfterUpgrade(4.0, 7.0));
    }
}
