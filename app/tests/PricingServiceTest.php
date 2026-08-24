<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\PricingService;
use App\Service\Season;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricingServiceTest extends TestCase
{
    private PricingService $pricing;
    private Season $season;

    protected function setUp(): void
    {
        $this->pricing = new PricingService(dirname(__DIR__, 2) . '/pricing_data');
        $this->season = new Season(2025);
    }

    /**
     * Every TOTAL cell of the club's 2025-2026 price table: subscription
     * cotisation + the licence(s) implied by competitor status.
     */
    #[DataProvider('priceTableProvider')]
    public function testPriceTableTotals(string $subscriptionKey, string $residence, bool $premiere, bool $isCouple, bool $competitor, float $expectedTotal): void
    {
        $people = $isCouple
            ? [['competitor' => $competitor, 'licenceRemoved' => false], ['competitor' => $competitor, 'licenceRemoved' => false]]
            : [['competitor' => $competitor, 'licenceRemoved' => false]];
        $quote = $this->pricing->quote($subscriptionKey, $residence, $premiere, $this->season, isCouple: $isCouple, people: $people);
        self::assertSame($expectedTotal, $quote->total());
    }

    public static function priceTableProvider(): array
    {
        $g = PricingService::RESIDENCE_GARENNOIS;
        $h = PricingService::RESIDENCE_HORS_COMMUNE;

        return [
            // HEURES PLEINES, non-compétiteur: cotisation + licence pass 20
            'HP garennois 1ère 239'        => ['heures-pleines', $g, true, false, false, 239.0],
            'HP garennois renouv 219'      => ['heures-pleines', $g, false, false, false, 219.0],
            'HP hors 1ère 385'             => ['heures-pleines', $h, true, false, false, 385.0],
            'HP hors renouv 303'           => ['heures-pleines', $h, false, false, false, 303.0],
            // HEURES PLEINES, compétiteur: cotisation + licence fédérale 55
            'HP comp garennois 1ère 274'   => ['heures-pleines', $g, true, false, true, 274.0],
            'HP comp garennois renouv 254' => ['heures-pleines', $g, false, false, true, 254.0],
            'HP comp hors 1ère 420'        => ['heures-pleines', $h, true, false, true, 420.0],
            'HP comp hors renouv 338'      => ['heures-pleines', $h, false, false, true, 338.0],
            // HEURES CREUSES: cotisation + pass 20
            'HC garennois 1ère 200'        => ['heures-creuses', $g, true, false, false, 200.0],
            'HC garennois renouv 180'      => ['heures-creuses', $g, false, false, false, 180.0],
            'HC hors 1ère 230'             => ['heures-creuses', $h, true, false, false, 230.0],
            'HC hors renouv 210'           => ['heures-creuses', $h, false, false, false, 210.0],
            // MIDI (garennois only): + pass 20
            'Midi 1ère 182'                => ['midi', $g, true, false, false, 182.0],
            'Midi renouv 162'              => ['midi', $g, false, false, false, 162.0],
            // MIDI, compétiteur: + fédérale 55
            'Midi comp 1ère 217'           => ['midi', $g, true, false, true, 217.0],
            'Midi comp renouv 197'         => ['midi', $g, false, false, true, 197.0],
            // JEUNE: cotisation + licence jeune 24 (competitor flag ignored for Jeune audience)
            'Jeune garennois 1ère 169'     => ['jeune', $g, true, false, false, 169.0],
            'Jeune garennois renouv 169'   => ['jeune', $g, false, false, false, 169.0],
            'Jeune hors 1ère 264'          => ['jeune', $h, true, false, false, 264.0],
            'Jeune hors renouv 222'        => ['jeune', $h, false, false, false, 222.0],
            // COUPLE HP, both non-compétiteur: cotisation + 2 × licence pass 40
            'Couple HP garennois 1ère 416' => ['heures-pleines', $g, true, true, false, 416.0],
            'Couple HP garennois ren 376'  => ['heures-pleines', $g, false, true, false, 376.0],
            'Couple HP hors 1ère 568'      => ['heures-pleines', $h, true, true, false, 568.0],
            'Couple HP hors renouv 468'    => ['heures-pleines', $h, false, true, false, 468.0],
            // COUPLE HC, both non-compétiteur: cotisation + 2 × licence pass 40
            'Couple HC garennois 1ère 320' => ['heures-creuses', $g, true, true, false, 320.0],
            'Couple HC garennois ren 280'  => ['heures-creuses', $g, false, true, false, 280.0],
            'Couple HC hors 1ère 428'      => ['heures-creuses', $h, true, true, false, 428.0],
            'Couple HC hors renouv 353'    => ['heures-creuses', $h, false, true, false, 353.0],
        ];
    }

    public function testProrataNovemberIsTwoTwelfthsOffCotisationOnly(): void
    {
        // Heures Pleines garennois 1ère : 219 × 10/12 = 182.50, licence 20 full price.
        $quote = $this->pricing->quote(
            'heures-pleines',
            PricingService::RESIDENCE_GARENNOIS,
            true,
            $this->season,
            joinDate: new DateTimeImmutable('2025-11-15'),
        );
        self::assertSame(2, $quote->prorataMonths);
        self::assertSame(182.5, $quote->lines[0]->amount);
        self::assertSame(219.0, $quote->lines[0]->baseAmount);
        self::assertSame(20.0, $quote->lines[1]->amount);
        self::assertSame(202.5, $quote->total());
    }

    public function testProrataAppliesToLessonsToo(): void
    {
        // Join in November: lessons 120 × 10/12 = 100.
        $quote = $this->pricing->quote(
            'heures-pleines',
            PricingService::RESIDENCE_GARENNOIS,
            true,
            $this->season,
            joinDate: new DateTimeImmutable('2025-11-02'),
            lessonsCount: 1,
        );
        $lessons = array_values(array_filter($quote->lines, fn ($l) => $l->type === 'lessons'))[0];
        self::assertSame(100.0, $lessons->amount);
    }

    public function testSeptemberJoinHasNoDiscountAndOctoberHasOneTwelfth(): void
    {
        $sept = $this->pricing->quote('heures-creuses', 'garennois', true, $this->season, new DateTimeImmutable('2025-09-25'));
        self::assertSame(0, $sept->prorataMonths);
        self::assertSame(200.0, $sept->total());

        $oct = $this->pricing->quote('heures-creuses', 'garennois', true, $this->season, new DateTimeImmutable('2025-10-05'));
        self::assertSame(1, $oct->prorataMonths);
        self::assertSame(165.0, $oct->lines[0]->amount); // 180 × 11/12
    }

    public function testLicenceRemovalDropsLicenceLine(): void
    {
        $quote = $this->pricing->quote(
            'heures-pleines',
            'garennois',
            false,
            $this->season,
            people: [['competitor' => true, 'licenceRemoved' => true]],
        );
        self::assertSame(199.0, $quote->total());
        self::assertSame([], array_filter($quote->lines, fn ($l) => $l->type === 'licence'));
    }

    public function testCoupleProducesTwoIndependentLicenceLines(): void
    {
        // Mixed statuses: applicant non-compétiteur (pass 20), partner compétiteur (fédérale 55).
        $quote = $this->pricing->quote(
            'heures-pleines',
            'garennois',
            true,
            $this->season,
            isCouple: true,
            people: [['competitor' => false, 'licenceRemoved' => false], ['competitor' => true, 'licenceRemoved' => false]],
        );
        $licenceLines = array_values(array_filter($quote->lines, fn ($l) => $l->type === 'licence'));
        self::assertCount(2, $licenceLines);
        self::assertSame(20.0, $licenceLines[0]->amount);
        self::assertSame(1, $licenceLines[0]->personIndex);
        self::assertTrue($licenceLines[0]->removable);
        self::assertSame(55.0, $licenceLines[1]->amount);
        self::assertSame(2, $licenceLines[1]->personIndex);
        self::assertSame(376.0 + 20.0 + 55.0, $quote->total());
    }

    public function testCoupleCanRemoveOnlyOnePartnersLicence(): void
    {
        $quote = $this->pricing->quote(
            'heures-pleines',
            'garennois',
            false,
            $this->season,
            isCouple: true,
            people: [['competitor' => false, 'licenceRemoved' => true], ['competitor' => false, 'licenceRemoved' => false]],
        );
        $licenceLines = array_values(array_filter($quote->lines, fn ($l) => $l->type === 'licence'));
        self::assertCount(1, $licenceLines);
        self::assertSame(2, $licenceLines[0]->personIndex);
        self::assertSame(336.0 + 20.0, $quote->total());
    }

    public function testCoupleRequiresMatchingPeopleCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pricing->quote('heures-pleines', 'garennois', true, $this->season, isCouple: true, people: [['competitor' => false, 'licenceRemoved' => false]]);
    }

    public function testCoupleNotAvailableForMidiOrJeune(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pricing->quote(
            'midi',
            'garennois',
            true,
            $this->season,
            isCouple: true,
            people: [['competitor' => false, 'licenceRemoved' => false], ['competitor' => false, 'licenceRemoved' => false]],
        );
    }

    public function testMidiRefusedOutsideGarennoisWithoutOverride(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pricing->quote('midi', PricingService::RESIDENCE_HORS_COMMUNE, true, $this->season);
    }

    public function testMidiResidencyOverrideChargesGarennoisPrice(): void
    {
        // Waives the residency requirement — a Hors-commune person pays the Garennois rate.
        $quote = $this->pricing->quote('midi', PricingService::RESIDENCE_HORS_COMMUNE, true, $this->season, midiResidencyOverride: true);
        self::assertSame(182.0, $quote->total()); // 162 (Garennois 1ère) + 20 (pass)
    }

    public function testJeuneCannotAddLessons(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pricing->quote('jeune', 'garennois', true, $this->season, lessonsCount: 1);
    }

    public function testCompetitorDrivesLicenceKindIndependently(): void
    {
        // Loisir member: pass licence.
        $loisir = $this->pricing->quote('heures-pleines', 'garennois', false, $this->season, people: [['competitor' => false, 'licenceRemoved' => false]]);
        self::assertSame(219.0, $loisir->total());
        self::assertSame('Licence Pass', $loisir->lines[1]->label);

        // Compétiteur: fédérale licence, same subscription tier.
        $competiteur = $this->pricing->quote('heures-pleines', 'garennois', false, $this->season, people: [['competitor' => true, 'licenceRemoved' => false]]);
        self::assertSame(254.0, $competiteur->total());
        self::assertSame('Licence fédérale', $competiteur->lines[1]->label);

        // Jeune subscription always gets the jeune licence, regardless of the competitor flag.
        $jeune = $this->pricing->quote('jeune', 'garennois', false, $this->season, people: [['competitor' => true, 'licenceRemoved' => false]]);
        self::assertSame(169.0, $jeune->total());
    }

    public function testSummerPackOverride(): void
    {
        // Renewal-shaped call (premiere: false): 50 € flat cotisation + 5 € licence été,
        // regardless of competitor status.
        $quote = $this->pricing->quote(
            'heures-pleines', 'garennois', false, $this->season,
            people: [['competitor' => true, 'licenceRemoved' => false]],
            summerPack: true,
        );
        self::assertSame(55.0, $quote->total()); // 50 (cotisation) + 5 (licence été)
        self::assertStringContainsString('Pack été saison', $quote->lines[0]->label);
        self::assertSame('Licence été (découverte)', $quote->lines[1]->label);

        // Join-shaped call (premiere: true) with a joinDate inside the season: the flat
        // cotisation must NOT be prorated — regression guard for the bug where a July/August
        // join's non-null joinDate would otherwise shrink the flat fee via the normal factor.
        $joined = $this->pricing->quote(
            'heures-pleines', 'garennois', true, $this->season,
            joinDate: $this->season->start()->modify('+10 months'), // ~ July
            summerPack: true,
        );
        self::assertSame(50.0, $joined->lines[0]->amount);
        self::assertSame(50.0, $joined->lines[0]->baseAmount);

        // Pack été is solo only — a couple registration must be rejected outright.
        $this->expectException(InvalidArgumentException::class);
        $this->pricing->quote(
            'heures-pleines',
            'garennois',
            false,
            $this->season,
            isCouple: true,
            people: [['competitor' => true, 'licenceRemoved' => false], ['competitor' => false, 'licenceRemoved' => false]],
            summerPack: true,
        );
    }

    public function testSummerPackRejectsLessons(): void
    {
        // No group lessons alongside the summer pack.
        $this->expectException(InvalidArgumentException::class);
        $this->pricing->quote('heures-pleines', 'garennois', true, $this->season, lessonsCount: 1, summerPack: true);
    }

    public function testSummerPackOffLeavesNormalPricingUntouched(): void
    {
        $normal = $this->pricing->quote('heures-pleines', 'garennois', false, $this->season);
        self::assertStringNotContainsString('Pack été', $normal->lines[0]->label);
        self::assertSame('Licence Pass', $normal->lines[1]->label);
    }

    public function testResidenceFromZip(): void
    {
        self::assertSame('garennois', $this->pricing->residenceForZip('92250'));
        self::assertSame('garennois', $this->pricing->residenceForZip(' 92250 '));
        self::assertSame('hors-commune', $this->pricing->residenceForZip('92700'));
    }

    public function testJeuneAndMinorThresholds(): void
    {
        $season = new Season(2025); // starts 2025-09-01
        // 19th birthday exactly on season start → adult tariff.
        self::assertFalse($this->pricing->isJeune(new DateTimeImmutable('2006-09-01'), $season));
        // Turns 19 the day after season start → jeune.
        self::assertTrue($this->pricing->isJeune(new DateTimeImmutable('2006-09-02'), $season));
        // Legal minor: under 18 "today".
        self::assertTrue($this->pricing->isMinor(new DateTimeImmutable('2008-09-02'), new DateTimeImmutable('2026-08-20')));
        self::assertFalse($this->pricing->isMinor(new DateTimeImmutable('2008-08-20'), new DateTimeImmutable('2026-08-20')));
    }

    public function testSubscriptionsForResidenceFiltersMidi(): void
    {
        $hors = $this->pricing->subscriptionsFor(PricingService::RESIDENCE_HORS_COMMUNE, $this->season);
        self::assertArrayNotHasKey('midi', $hors);
        self::assertArrayHasKey('heures-pleines', $hors);

        $garennois = $this->pricing->subscriptionsFor(PricingService::RESIDENCE_GARENNOIS, $this->season);
        self::assertArrayHasKey('midi', $garennois);
    }

    public function testSubscriptionsForMidiResidencyOverrideIncludesMidiForHorsCommune(): void
    {
        $hors = $this->pricing->subscriptionsFor(PricingService::RESIDENCE_HORS_COMMUNE, $this->season, midiResidencyOverride: true);
        self::assertArrayHasKey('midi', $hors);
    }

    public function testUnknownSeasonFallsBackToLatestAvailableCatalogue(): void
    {
        // 2099-2100 doesn't have a pricing file yet — falls back to the
        // most recent one instead of throwing, so nothing breaks while an
        // admin hasn't published next season's numbers.
        $quote = $this->pricing->quote('heures-pleines', 'garennois', false, new Season(2099));
        self::assertSame(219.0, $quote->total()); // 199 (renouvellement) + 20 (Licence Pass)
    }
}
