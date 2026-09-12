<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\CheckoutAbbreviations;
use PHPUnit\Framework\TestCase;

final class CheckoutAbbreviationsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/checkout_abbrev_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/checkout_abbreviations.php');
        @rmdir($this->dir);
    }

    /** @param array<string, string>|string $overrides */
    private function withOverrides(array|string $overrides): CheckoutAbbreviations
    {
        file_put_contents(
            $this->dir . '/checkout_abbreviations.php',
            "<?php return " . var_export($overrides, true) . ';',
        );

        return new CheckoutAbbreviations($this->dir);
    }

    public function testAppliesTheShippedDefaultsWithNoOverrideFile(): void
    {
        $abbreviations = new CheckoutAbbreviations($this->dir);

        self::assertSame(
            'Cotis. — Heures Pleines — Couple (renouv.)',
            $abbreviations->apply('Cotisation — Heures Pleines — Couple (renouvellement)'),
        );
        self::assertSame('Lic. fédérale — conj.', $abbreviations->apply('Licence fédérale — conjoint(e)'));
        self::assertSame('Promo RENTREE25', $abbreviations->apply('Réduction — code RENTREE25'));
        self::assertSame('Cours coll. (vers. 1/3)', $abbreviations->apply('Cours collectifs (adultes) (versement 1/3)'));
        self::assertSame('Jeune (-19 ans)', $abbreviations->apply('Jeune (- de 19 ans) — mini-squash / école des jeunes inclus'));
    }

    public function testLeavesAnUnknownLabelAlone(): void
    {
        $abbreviations = new CheckoutAbbreviations($this->dir);

        self::assertSame('Stage de Pâques', $abbreviations->apply('Stage de Pâques'));
    }

    /**
     * Longest search first is what makes the table order-independent: a short
     * fragment contained in a longer one must not win.
     */
    public function testTheLongestMatchingFragmentWins(): void
    {
        $abbreviations = $this->withOverrides(['Licence' => 'L.']);

        self::assertSame('Lic. fédérale', $abbreviations->apply('Licence fédérale'));
        // The shorter entry still applies where nothing longer matches.
        self::assertSame('L. vétéran', $abbreviations->apply('Licence vétéran'));
    }

    public function testAnOverrideReplacesADefault(): void
    {
        $abbreviations = $this->withOverrides(['Cotisation' => 'Abo.']);

        self::assertSame('Abo. — Heures Creuses (renouv.)', $abbreviations->apply('Cotisation — Heures Creuses (renouvellement)'));
    }

    public function testAnOverrideCanAddAFragmentTheDefaultsDoNotKnow(): void
    {
        $abbreviations = $this->withOverrides(['Cours collectifs (jeunes)' => 'Cours coll. jeunes']);

        self::assertSame('Cours coll. jeunes × 2', $abbreviations->apply('Cours collectifs (jeunes) × 2'));
    }

    /** A malformed file must not take the payment path down with it. */
    public function testIgnoresAnOverrideFileThatIsNotAnArray(): void
    {
        $abbreviations = $this->withOverrides('oops');

        self::assertSame('Cotis. — Midi', $abbreviations->apply('Cotisation — Midi (11h45-13h15, lun-ven)'));
    }

    public function testEveryEntryActuallyShortens(): void
    {
        foreach ((new CheckoutAbbreviations($this->dir))->table() as $search => $replacement) {
            self::assertLessThan(
                mb_strlen((string) $search),
                mb_strlen($replacement),
                "abbreviation for '{$search}' is not shorter than the original",
            );
        }
    }
}
