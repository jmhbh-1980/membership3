<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\PricingCsvCodec;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PricingCsvCodecTest extends TestCase
{
    private PricingCsvCodec $codec;
    private array $catalogue;

    protected function setUp(): void
    {
        $this->codec = new PricingCsvCodec();
        $this->catalogue = require dirname(__DIR__, 2) . '/pricing_data/pricing.2025-2026.php';
    }

    public function testRoundTripPreservesEveryValue(): void
    {
        $csv = $this->codec->toCsv($this->catalogue);
        $parsed = $this->codec->fromCsv($csv, array_keys($this->catalogue['subscriptions']));

        self::assertSame($this->catalogue['subscriptions'], $parsed['subscriptions']);
        self::assertSame($this->catalogue['licences'], $parsed['licences']);
        self::assertSame($this->catalogue['lessons'], $parsed['lessons']);
        self::assertSame($this->catalogue['ticket_pack'], $parsed['ticket_pack']);
    }

    public function testMissingResidenceStaysMissing(): void
    {
        // midi has no "hors-commune" price in the source catalogue at all.
        $csv = $this->codec->toCsv($this->catalogue);
        $parsed = $this->codec->fromCsv($csv, array_keys($this->catalogue['subscriptions']));

        self::assertArrayNotHasKey('hors-commune', $parsed['subscriptions']['midi']['individual']);
        self::assertArrayHasKey('garennois', $parsed['subscriptions']['midi']['individual']);
    }

    public function testCoupleNotAvailableSubscriptionHasNoCoupleGrid(): void
    {
        $csv = $this->codec->toCsv($this->catalogue);
        $parsed = $this->codec->fromCsv($csv, array_keys($this->catalogue['subscriptions']));

        self::assertArrayNotHasKey('couple', $parsed['subscriptions']['midi']);
        self::assertArrayHasKey('couple', $parsed['subscriptions']['heures-pleines']);
    }

    public function testMissingSubscriptionIsRejected(): void
    {
        $csv = $this->codec->toCsv($this->catalogue);
        $lines = array_filter(
            explode("\n", trim($csv)),
            fn ($line) => !str_starts_with($line, 'subscription,heures-creuses,'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('heures-creuses');
        $this->codec->fromCsv(implode("\n", $lines), array_keys($this->catalogue['subscriptions']));
    }

    public function testUnknownSubscriptionKeyIsRejected(): void
    {
        $csv = "type,key,grid,label,audience,couple_available,bj_subscription,tickets,garennois_premiere,garennois_renouvellement,hors_commune_premiere,hors_commune_renouvellement\n"
            . 'subscription,une-formule-qui-nexiste-pas,individual,"Test",adulte,0,"_Abonnement Individuel - Heures Pleines",,100,100,,';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('une-formule-qui-nexiste-pas');
        $this->codec->fromCsv($csv, ['heures-pleines']);
    }

    public function testNonNumericPriceIsRejected(): void
    {
        $csv = "type,key,grid,label,audience,couple_available,bj_subscription,tickets,garennois_premiere,garennois_renouvellement,hors_commune_premiere,hors_commune_renouvellement\n"
            . 'subscription,heures-pleines,individual,"Test",adulte,0,"_Abonnement Individuel - Heures Pleines",,abc,100,,';

        $this->expectException(InvalidArgumentException::class);
        $this->codec->fromCsv($csv, ['heures-pleines']);
    }
}
