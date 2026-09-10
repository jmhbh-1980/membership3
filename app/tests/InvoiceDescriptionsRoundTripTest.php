<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\InvoiceDescriptions;
use App\Service\PricingFileWriter;
use PHPUnit\Framework\TestCase;

/**
 * The editor writes invoice_descriptions.php with PricingFileWriter and
 * InvoiceDescriptions reads it back with require. Those two have to agree, and
 * the file is generated from admin free text — apostrophes, accents and
 * backslashes all reach it — so a quoting slip would produce a PHP parse error
 * in a file the whole invoice path requires.
 *
 * Writes to a temp directory; touches neither the real pricing_data/ nor the DB.
 */
final class InvoiceDescriptionsRoundTripTest extends TestCase
{
    private string $dir;
    private PricingFileWriter $writer;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/invoice_descriptions_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->writer = new PricingFileWriter();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    private function writeThenRead(array $table): InvoiceDescriptions
    {
        $this->writer->write($this->dir . '/invoice_descriptions.php', $table);
        return new InvoiceDescriptions($this->dir);
    }

    public function testTextSurvivesTheWriteAndReadBack(): void
    {
        $descriptions = $this->writeThenRead([
            'subscriptions' => ['heures-pleines' => 'Accès libre à tous les créneaux.'],
            'licences'      => ['pass' => 'Pratique occasionnelle.'],
            'ticket_pack'   => 'Carnet de 5 séances.',
            'summer_pack'   => 'Offre de fin de saison.',
        ]);

        self::assertSame('Accès libre à tous les créneaux.', $descriptions->subscriptionBlurb('heures-pleines'));
        self::assertSame('Pratique occasionnelle.', $descriptions->licenceBlurb('pass'));
        self::assertSame('Carnet de 5 séances.', $descriptions->ticketPackBlurb());
        self::assertSame('Offre de fin de saison.', $descriptions->summerPackBlurb());
    }

    public function testQuotesAndBackslashesDoNotBreakTheGeneratedFile(): void
    {
        // Every one of these is something an admin could plausibly type, and any
        // of them mis-escaped turns the file into a parse error rather than a
        // wrong blurb — which would take down invoice generation entirely.
        $awkward = 'L\'accès "libre" \\ 100% — cf. horaires du club';

        $descriptions = $this->writeThenRead(['subscriptions' => ['heures-pleines' => $awkward]]);

        self::assertSame($awkward, $descriptions->subscriptionBlurb('heures-pleines'));
    }

    public function testUnknownKeysAndAnAbsentFileReturnEmptyRatherThanFailing(): void
    {
        $descriptions = $this->writeThenRead(['subscriptions' => ['heures-pleines' => 'Texte.']]);
        self::assertSame('', $descriptions->subscriptionBlurb('formule-inexistante'));
        self::assertSame('', $descriptions->licenceBlurb('pass'), 'no licences key written at all');

        // Before the file has ever been saved: every blurb is simply empty, and
        // the invoice prints without one.
        $empty = new InvoiceDescriptions($this->dir . '/nowhere');
        self::assertSame('', $empty->subscriptionBlurb('heures-pleines'));
        self::assertSame('', $empty->summerPackBlurb());
    }
}
