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
