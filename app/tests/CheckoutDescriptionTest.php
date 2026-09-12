<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\CheckoutAbbreviations;
use App\Service\CheckoutDescription;
use App\Service\InvoiceLineComposer;
use PHPUnit\Framework\TestCase;

final class CheckoutDescriptionTest extends TestCase
{
    private const FALLBACK = 'Renouvellement Bad & Squash — saison 2026-2027';

    private CheckoutDescription $description;

    protected function setUp(): void
    {
        // The real abbreviation table: its defaults ship in code, and the
        // optional pricing_data override is absent in most environments.
        $this->description = new CheckoutDescription(
            new CheckoutAbbreviations(dirname(__DIR__, 2) . '/pricing_data'),
        );
    }

    /** @param list<array<string, mixed>> $lines */
    private static function order(array $lines): array
    {
        return ['cart_lines' => json_encode($lines, JSON_UNESCAPED_UNICODE)];
    }

    public function testListsEveryLineLabelInOrder(): void
    {
        $order = self::order([
            ['type' => 'cotisation', 'label' => 'Cotisation — Heures Pleines (renouvellement)', 'amount' => 250.0],
            ['type' => 'licence', 'label' => 'Licence fédérale', 'amount' => 60.0],
            ['type' => 'lessons', 'label' => 'Cours collectifs (adultes) × 2', 'amount' => 120.0],
        ]);

        self::assertSame(
            'Cotisation — Heures Pleines (renouvellement) + Licence fédérale + Cours collectifs (adultes) × 2',
            $this->description->forOrder($order, self::FALLBACK),
        );
    }

    /** The checkout amount is already net of the discount, so naming it is what explains the gap. */
    public function testKeepsDiscountLines(): void
    {
        $order = self::order([
            ['type' => 'cotisation', 'label' => 'Cotisation — Heures Creuses (1ère inscription)', 'amount' => 200.0],
            ['type' => 'discount', 'label' => 'Réduction — code RENTREE25', 'amount' => -20.0],
        ]);

        self::assertSame(
            'Cotisation — Heures Creuses (1ère inscription) + Réduction — code RENTREE25',
            $this->description->forOrder($order, self::FALLBACK),
        );
    }

    /**
     * A part-season join is charged a part-season price, so the receipt says so
     * — at the same percentage the invoice prints in its "Réduc." column.
     */
    public function testNamesTheProrataOnTheLinesThatCarryOne(): void
    {
        $order = self::order([
            ['type' => 'cotisation', 'label' => 'Cotisation — Heures Creuses (1ère inscription)', 'amount' => 100.0, 'baseAmount' => 150.0],
            ['type' => 'licence', 'label' => 'Licence Pass', 'amount' => 60.0, 'baseAmount' => 60.0],
            ['type' => 'lessons', 'label' => 'Cours collectifs (adultes)', 'amount' => 80.0, 'baseAmount' => 120.0],
        ]);

        self::assertSame(
            'Cotisation — Heures Creuses (1ère inscription) — prorata -33% + Licence Pass'
            . ' + Cours collectifs (adultes) — prorata -33%',
            $this->description->forOrder($order, self::FALLBACK),
        );
    }

    /** Same percentage the invoice quotes for the same line — one definition, no drift. */
    public function testProrataMatchesTheInvoiceColumn(): void
    {
        $order = self::order([
            ['type' => 'cotisation', 'label' => 'Cotisation — Midi (1ère inscription)', 'amount' => 94.67, 'baseAmount' => 142.0],
        ]);

        self::assertStringEndsWith(
            ' — prorata -' . InvoiceLineComposer::reducFor(142.0, 94.67),
            $this->description->forOrder($order, self::FALLBACK),
        );
    }

    /** A single-line order (credits, lessons) reads as just that item. */
    public function testSingleLineNeedsNoSeparator(): void
    {
        $order = self::order([['type' => 'tickets', 'label' => 'Formule Tickets — 5 séances', 'amount' => 60.0]]);

        self::assertSame('Formule Tickets — 5 séances', $this->description->forOrder($order, self::FALLBACK));
    }

    /**
     * Installment lines already carry "(versement n/N)" in their stored label
     * (RenewalController::startInstallmentPlan), so the description says which
     * installment a partial charge is without any special casing here.
     */
    public function testInstallmentLabelsCarryTheirOwnNumbering(): void
    {
        $order = self::order([
            ['type' => 'cotisation', 'label' => 'Cotisation — Heures Pleines (renouvellement) (versement 2/3)', 'amount' => 83.34],
        ]);

        self::assertSame(
            'Cotisation — Heures Pleines (renouvellement) (versement 2/3)',
            $this->description->forOrder($order, self::FALLBACK),
        );
    }

    /**
     * The cart that used to overflow: couple, mid-season, two licences, two
     * cours and a promo code, 202 characters written out in full. Abbreviating
     * keeps every item AND the prorata, where before the prorata was dropped.
     */
    public function testAbbreviatesRatherThanDroppingTheProrata(): void
    {
        $order = self::order([
            ['type' => 'cotisation', 'label' => 'Cotisation — Heures Pleines — Couple (1ère inscription)', 'amount' => 293.33, 'baseAmount' => 438.0],
            ['type' => 'licence', 'label' => 'Licence fédérale — vous', 'amount' => 60.0, 'baseAmount' => 60.0],
            ['type' => 'licence', 'label' => 'Licence Pass — conjoint(e)', 'amount' => 40.0, 'baseAmount' => 40.0],
            ['type' => 'lessons', 'label' => 'Cours collectifs (adultes) × 2', 'amount' => 160.0, 'baseAmount' => 240.0],
            ['type' => 'discount', 'label' => 'Réduction — code RENTREE25', 'amount' => -45.33, 'baseAmount' => -45.33],
        ]);

        $description = $this->description->forOrder($order, self::FALLBACK);

        self::assertLessThanOrEqual(200, mb_strlen($description));
        self::assertStringContainsString('prorata', $description);
        self::assertStringContainsString('Cotis. — Heures Pleines — Couple (1ère insc.)', $description);
        self::assertStringContainsString('Cours coll. × 2', $description);
        self::assertStringContainsString('Promo RENTREE25', $description);
    }

    /** Abbreviation is a fallback, not the default: a cart that fits keeps its full labels. */
    public function testDoesNotAbbreviateWhenTheFullLabelsFit(): void
    {
        $order = self::order([['type' => 'cotisation', 'label' => 'Cotisation — Heures Pleines (renouvellement)', 'amount' => 199.0]]);

        self::assertStringContainsString('Cotisation', $this->description->forOrder($order, self::FALLBACK));
    }

    public function testFallsBackWhenTheOrderHasNoLines(): void
    {
        self::assertSame(self::FALLBACK, $this->description->forOrder(self::order([]), self::FALLBACK));
        self::assertSame(self::FALLBACK, $this->description->forOrder([], self::FALLBACK));
        self::assertSame(self::FALLBACK, $this->description->forOrder(['cart_lines' => 'not json'], self::FALLBACK));
    }

    public function testSkipsLinesWithNoLabel(): void
    {
        $order = self::order([
            ['type' => 'cotisation', 'label' => 'Cotisation — Midi (1ère inscription)', 'amount' => 150.0],
            ['type' => 'licence', 'label' => '   ', 'amount' => 60.0],
        ]);

        self::assertSame('Cotisation — Midi (1ère inscription)', $this->description->forOrder($order, self::FALLBACK));
    }

    /** Beyond even abbreviation: the flow's own summary, never a partial list. */
    public function testFallsBackWhenNothingFits(): void
    {
        $lines = [];
        for ($i = 1; $i <= 12; $i++) {
            $lines[] = ['type' => 'lessons', 'label' => "Cours collectifs (adultes) — créneau {$i}", 'amount' => 60.0];
        }
        $lines[] = ['type' => 'discount', 'label' => 'Réduction — code RENTREE25', 'amount' => -40.0];

        self::assertSame(self::FALLBACK, $this->description->forOrder(self::order($lines), self::FALLBACK));
    }

    public function testFallsBackOnASingleOversizedLabel(): void
    {
        $order = self::order([['type' => 'cotisation', 'label' => str_repeat('Cotisation ', 40), 'amount' => 250.0]]);

        self::assertSame(self::FALLBACK, $this->description->forOrder($order, self::FALLBACK));
    }
}
