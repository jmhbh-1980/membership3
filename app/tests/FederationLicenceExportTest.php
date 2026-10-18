<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\FederationLicenceExport;
use App\Service\Season;
use PHPUnit\Framework\TestCase;

/**
 * The files uploaded to the FFSquash extranet's "saisie de licences par
 * fichier". The extranet's own rules are pinned here: column order,
 * semicolons, jj/mm/aaaa dates, M/F, Pays empty for France, and at most 100
 * lines per file (it keeps only the first 100 valid lines of a file).
 */
final class FederationLicenceExportTest extends TestCase
{
    private FederationLicenceExport $export;

    protected function setUp(): void
    {
        $this->export = new FederationLicenceExport('A092021');
    }

    private static function user(array $overrides = []): array
    {
        return $overrides + [
            'user_id'        => 1,
            'lastname'       => 'DUPONT',
            'firstname'      => 'Jean-Marie',
            'birthday'       => '1985-03-07',
            'sex'            => 'M',
            'email'          => 'jean.dupont@example.com',
            'email2'         => '',
            'phone1'         => '0601020304',
            'phone2'         => '',
            'address'        => '12 rue des Lilas',
            'postalcode'     => '92250',
            'city'           => 'La Garenne-Colombes',
            'country'        => 'FR',
            'license_number' => '',
            'licenceKind'    => ['kind' => 'pass', 'detail' => ''],
        ];
    }

    public function testRowFollowsTheFederationColumnsAndFormats(): void
    {
        $row = $this->export->row(self::user(['license_number' => '12345', 'sex' => 'F']));

        self::assertSame(array_combine(FederationLicenceExport::COLUMNS, [
            'A092021', '12345', 'DUPONT', 'Jean-Marie', '07/03/1985', 'F', 'jean.dupont@example.com',
            '0601020304', '12 rue des Lilas', '', '', '92250', 'La Garenne-Colombes', '',
        ]), array_combine(FederationLicenceExport::COLUMNS, $row));
    }

    public function testFallsBackToTheSecondEmailAndPhone(): void
    {
        $row = $this->export->row(self::user(['email' => '', 'email2' => 'autre@example.com', 'phone1' => '', 'phone2' => '0700000000']));

        self::assertSame('autre@example.com', $row[6]);
        self::assertSame('0700000000', $row[7]);
    }

    public function testCountryIsLeftEmptyForFranceAndNamedOtherwise(): void
    {
        self::assertSame('', $this->export->row(self::user(['country' => '']))[13]);
        self::assertSame('', $this->export->row(self::user(['country' => 'fr']))[13]);
        self::assertNotSame('', $this->export->row(self::user(['country' => 'BE']))[13]);
    }

    public function testListsWhatTheFederationRequiresAndBalleJauneLacks(): void
    {
        self::assertSame([], $this->export->missingFields(self::user()));
        self::assertSame(
            ['date de naissance', 'sexe', 'email', 'code postal'],
            $this->export->missingFields(self::user([
                'birthday' => '0000-00-00', 'sex' => '', 'email' => '', 'postalcode' => ' ',
            ])),
        );
        // Optional for the federation: no phone, no address line, no licence number yet.
        self::assertSame([], $this->export->missingFields(self::user(['phone1' => '', 'address' => '', 'license_number' => ''])));
    }

    public function testOneFilePerLicenceKindWithAHeaderLine(): void
    {
        $files = $this->export->files([
            self::user(['user_id' => 1, 'lastname' => 'A', 'licenceKind' => ['kind' => 'federale']]),
            self::user(['user_id' => 2, 'lastname' => 'B']),
            self::user(['user_id' => 3, 'lastname' => 'C', 'licenceKind' => ['kind' => 'federale']]),
        ], new Season(2026));

        self::assertSame(['ffsquash-licences-pass-2026-2027.csv', 'ffsquash-licences-federale-2026-2027.csv'], array_keys($files));
        $lines = explode("\r\n", rtrim($files['ffsquash-licences-federale-2026-2027.csv'], "\r\n"));
        self::assertSame(implode(';', FederationLicenceExport::COLUMNS), $lines[0]);
        self::assertCount(3, $lines);
        self::assertStringStartsWith('A092021;;A;Jean-Marie;07/03/1985;M;', $lines[1]);
    }

    public function testLeavesOutWaivedUndeterminedAndIncompleteMembers(): void
    {
        $files = $this->export->files([
            self::user(['licenceKind' => ['kind' => 'retiree']]),
            self::user(['licenceKind' => ['kind' => 'inconnue']]),
            self::user(['sex' => '']),
        ], new Season(2026));

        self::assertSame([], $files);
    }

    public function testSplitsEveryHundredLines(): void
    {
        $users = array_map(fn (int $i) => self::user(['user_id' => $i, 'lastname' => 'NOM' . $i]), range(1, 201));

        $files = $this->export->files($users, new Season(2026));

        self::assertSame([
            'ffsquash-licences-pass-2026-2027-1-sur-3.csv',
            'ffsquash-licences-pass-2026-2027-2-sur-3.csv',
            'ffsquash-licences-pass-2026-2027-3-sur-3.csv',
        ], array_keys($files));
        $counts = array_map(fn (string $csv) => substr_count($csv, "\r\n") - 1, array_values($files));
        self::assertSame([100, 100, 1], $counts);
    }

    public function testAValueWithASemicolonIsQuoted(): void
    {
        $csv = $this->export->files([self::user(['address' => 'Bât. A; 2e étage'])], new Season(2026));

        self::assertStringContainsString(';"Bât. A; 2e étage";', (string) reset($csv));
    }
}
