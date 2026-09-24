<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\InvoiceDescriptions;
use App\Service\InvoiceLineComposer;
use App\Service\PricingService;
use App\Service\Season;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test against the real pricing_data/ catalogue (read-only here —
 * nothing in this test mutates it) — only needs the 'licences' section,
 * which every published season keeps in the same shape.
 */
final class InvoiceLineComposerTest extends TestCase
{
    private InvoiceLineComposer $composer;
    private Season $season;

    protected function setUp(): void
    {
        $configDir = dirname(__DIR__, 2) . '/pricing_data';
        $pricing = new PricingService($configDir);
        $descriptions = new InvoiceDescriptions($configDir);
        $this->composer = new InvoiceLineComposer($pricing, $descriptions);
        $this->season = new Season(2026);
    }

    private function line(string $type, string $label, float $amount, ?float $baseAmount = null, ?int $personIndex = null): array
    {
        return ['type' => $type, 'label' => $label, 'amount' => $amount, 'baseAmount' => $baseAmount ?? $amount, 'personIndex' => $personIndex];
    }

    public function testATicketsJoinPrintsThePackWithItsBlurbAndNoResidenceOrLicence(): void
    {
        $configDir = sys_get_temp_dir() . '/invoice-tickets-' . bin2hex(random_bytes(4));
        mkdir($configDir);
        file_put_contents($configDir . '/invoice_descriptions.php', "<?php return ['ticket_pack' => '5 séances, sans date limite.'];");
        $composer = new InvoiceLineComposer(new PricingService(dirname(__DIR__, 2) . '/pricing_data'), new InvoiceDescriptions($configDir));

        $rows = $composer->compose(['lines' => [$this->line('tickets', 'Formule Tickets — 5 séances', 10.0)]], [
            'subscription'    => ['audience' => PricingService::TICKETS],
            'subscriptionKey' => PricingService::TICKETS,
            'season'          => $this->season,
            'residence'       => PricingService::RESIDENCE_GARENNOIS,
            'summerPack'      => false,
            'people'          => [['competitor' => false, 'licenceRemoved' => false]],
        ]);

        unlink($configDir . '/invoice_descriptions.php');
        rmdir($configDir);
        self::assertCount(1, $rows);
        self::assertSame('Formule Tickets — 5 séances', $rows[0]['description']);
        self::assertSame('5 séances, sans date limite.', $rows[0]['blurb']);
        self::assertSame(10.0, $rows[0]['amount']);
    }

    public function testSoloGarennoisRenewalAppendsResidenceAndLicence(): void
    {
        $breakdown = ['lines' => [
            $this->line('cotisation', 'Cotisation — Heures Creuses (renouvellement)', 199.0),
            $this->line('licence', 'Licence Pass', 20.0),
        ]];
        $context = [
            'subscription'    => ['audience' => 'adulte'],
            'subscriptionKey' => 'heures-creuses',
            'season'          => $this->season,
            'residence'       => PricingService::RESIDENCE_GARENNOIS,
            'summerPack'      => false,
            'people'          => [['competitor' => false, 'licenceRemoved' => false]],
        ];

        $rows = $this->composer->compose($breakdown, $context);

        self::assertSame(
            'Cotisation — Heures Creuses (renouvellement) — Garennois(e) — licence Licence Pass',
            $rows[0]['description'],
        );
        self::assertSame('0', $rows[0]['reduc']);
        self::assertSame('Licence Pass', $rows[1]['description']);
    }

    public function testAPromoCodesOwnBlurbPrintsUnderItsDiscountLine(): void
    {
        $breakdown = [
            'lines' => [$this->line('discount', 'Réduction — code PARRAINAGE', -21.9)],
            'promoBlurb' => 'Offre de parrainage — remise accordée par le club.',
        ];

        $rows = $this->composer->compose($breakdown, $this->soloContext());

        self::assertSame('Réduction — code PARRAINAGE', $rows[0]['description']);
        self::assertSame('Offre de parrainage — remise accordée par le club.', $rows[0]['blurb']);
    }

    public function testADiscountWithoutAPromoBlurbPrintsNoBlurb(): void
    {
        // Two ways to get here: a promo code whose blurb was left empty (the
        // normal case), and the student discount, which has no promo code at all
        // and so never has one to offer.
        foreach ([[], ['promoBlurb' => '']] as $extra) {
            $breakdown = ['lines' => [$this->line('discount', 'Réduction — statut étudiant', -109.5)]] + $extra;

            $rows = $this->composer->compose($breakdown, $this->soloContext());

            self::assertSame('', $rows[0]['blurb']);
        }
    }

    /** @return array the minimal context a discount-only breakdown still needs */
    private function soloContext(): array
    {
        return [
            'subscription'     => ['audience' => 'adulte'],
            'subscriptionKey'  => 'heures-pleines',
            'season'           => $this->season,
            'residence'        => PricingService::RESIDENCE_GARENNOIS,
            'pricingResidence' => PricingService::RESIDENCE_GARENNOIS,
            'summerPack'       => false,
            'people'           => [['competitor' => false, 'licenceRemoved' => false]],
        ];
    }

    public function testResidenceExceptionStatesBothTheFactAndTheGrantedTariff(): void
    {
        // An invoice must never claim a non-resident is Garennois(e): the
        // factual residence stays, and the granted tariff is named next to it
        // so the printed price and the stated residence add up.
        $breakdown = ['lines' => [
            $this->line('cotisation', 'Cotisation — Heures Pleines (renouvellement)', 199.0),
        ]];
        $context = [
            'subscription'     => ['audience' => 'adulte'],
            'subscriptionKey'  => 'heures-pleines',
            'season'           => $this->season,
            'residence'        => PricingService::RESIDENCE_HORS_COMMUNE,
            'pricingResidence' => PricingService::RESIDENCE_GARENNOIS,
            'summerPack'       => false,
            'people'           => [['competitor' => false, 'licenceRemoved' => false]],
        ];

        $rows = $this->composer->compose($breakdown, $context);

        self::assertStringContainsString('Hors commune', $rows[0]['description']);
        self::assertStringContainsString('tarif Garennois accordé à titre exceptionnel', $rows[0]['description']);
        self::assertStringNotContainsString('Garennois(e)', $rows[0]['description']);
    }

    public function testWithoutAnExceptionTheWordingIsUnchanged(): void
    {
        $breakdown = ['lines' => [
            $this->line('cotisation', 'Cotisation — Heures Pleines (renouvellement)', 283.0),
        ]];
        $context = [
            'subscription'     => ['audience' => 'adulte'],
            'subscriptionKey'  => 'heures-pleines',
            'season'           => $this->season,
            'residence'        => PricingService::RESIDENCE_HORS_COMMUNE,
            'pricingResidence' => PricingService::RESIDENCE_HORS_COMMUNE,
            'summerPack'       => false,
            'people'           => [['competitor' => false, 'licenceRemoved' => false]],
        ];

        $rows = $this->composer->compose($breakdown, $context);

        self::assertStringNotContainsString('exceptionnel', $rows[0]['description']);
    }

    public function testCoupleWithMixedCompetitorStatusListsBothLicences(): void
    {
        $breakdown = ['lines' => [
            $this->line('cotisation', 'Cotisation — Heures Pleines — Couple (renouvellement)', 428.0),
        ]];
        $context = [
            'subscription'    => ['audience' => 'adulte'],
            'subscriptionKey' => 'heures-pleines',
            'season'          => $this->season,
            'residence'       => PricingService::RESIDENCE_HORS_COMMUNE,
            'summerPack'      => false,
            'people'          => [
                ['competitor' => true, 'licenceRemoved' => false],
                ['competitor' => false, 'licenceRemoved' => false],
            ],
        ];

        $rows = $this->composer->compose($breakdown, $context);

        self::assertStringContainsString('Hors commune', $rows[0]['description']);
        self::assertStringContainsString('licences :', $rows[0]['description']);
        self::assertStringContainsString('(vous)', $rows[0]['description']);
        self::assertStringContainsString('(conjoint(e))', $rows[0]['description']);
    }

    public function testJeuneForcesJeuneLicenceRegardlessOfCompetitorFlag(): void
    {
        $breakdown = ['lines' => [
            $this->line('cotisation', 'Cotisation — Jeune (- de 19 ans) — mini-squash / école des jeunes inclus (1ère inscription)', 145.0),
        ]];
        $context = [
            'subscription'    => ['audience' => 'jeune'],
            'subscriptionKey' => 'jeune',
            'season'          => $this->season,
            'residence'       => PricingService::RESIDENCE_GARENNOIS,
            'summerPack'      => false,
            'people'          => [['competitor' => true, 'licenceRemoved' => false]],
        ];

        $rows = $this->composer->compose($breakdown, $context);

        self::assertStringContainsString('licence Licence jeune', $rows[0]['description']);
    }

    public function testSummerPackSkipsResidenceButKeepsLicenceSuffix(): void
    {
        $breakdown = ['lines' => [
            $this->line('cotisation', 'Cotisation — Pack été saison 2026-2027 (tarif unique)', 50.0),
        ]];
        $context = [
            'subscription'    => ['audience' => 'adulte'],
            'subscriptionKey' => 'heures-pleines',
            'season'          => $this->season,
            'residence'       => PricingService::RESIDENCE_GARENNOIS,
            'summerPack'      => true,
            'people'          => [['competitor' => false, 'licenceRemoved' => false]],
        ];

        $rows = $this->composer->compose($breakdown, $context);

        self::assertStringNotContainsString('Garennois', $rows[0]['description']);
        self::assertStringContainsString('licence Licence été', $rows[0]['description']);
    }

    public function testDiscountLineAndLessonsQuantityAndProrataPercent(): void
    {
        $breakdown = ['lines' => [
            $this->line('lessons', 'Cours collectifs (adultes) × 2', 220.0, 240.0),
            $this->line('discount', 'Réduction — code TEST', -15.0, -15.0),
        ]];
        $context = [
            'subscription'    => ['audience' => 'adulte'],
            'subscriptionKey' => 'heures-pleines',
            'season'          => $this->season,
            'residence'       => PricingService::RESIDENCE_GARENNOIS,
            'summerPack'      => false,
            'people'          => [['competitor' => false, 'licenceRemoved' => false]],
        ];

        $rows = $this->composer->compose($breakdown, $context);

        self::assertSame(2, $rows[0]['quantity']);
        self::assertSame(120.0, $rows[0]['unitPrice']);
        self::assertSame('8%', $rows[0]['reduc']);
        self::assertSame('Réduction — code TEST', $rows[1]['description']);
        self::assertSame('0', $rows[1]['reduc']);
    }
}
