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
 * lines per file (it keeps only the first 100 valid lines of a file) — and
 * text the way the federation keeps it: upper case, ASCII, in Windows-1252.
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
            'A092021', '12345', 'DUPONT', 'JEAN-MARIE', '07/03/1985', 'F', 'jean.dupont@example.com',
            '0601020304', '12 RUE DES LILAS', '', '', '92250', 'LA GARENNE-COLOMBES', '',
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
        // Named in French, then like any other text: "États-Unis" would otherwise carry its accent.
        self::assertMatchesRegularExpression('/^[A-Z -]+$/', $this->export->row(self::user(['country' => 'US']))[13]);
    }

    /**
     * What the federation's own data holds where Balle Jaune has an accent, an
     * apostrophe or a line break (its licence export, compared member by member).
     */
    public function testTextIsWrittenTheWayTheFederationKeepsIt(): void
    {
        $cases = [
            'Hélène'                       => 'HELENE',
            'Sébastien'                    => 'SEBASTIEN',
            'Asnières-sur-Seine'           => 'ASNIERES-SUR-SEINE',
            'Gaëlle Françoise'             => 'GAELLE FRANCOISE',
            'Anaïs Noël Côme Lætitia'      => 'ANAIS NOEL COME LAETITIA',
            'Œuvre Ærø Straße Łódź'        => 'OEUVRE AERO STRASSE LODZ',
            "e\u{0301}lodie"               => 'ELODIE',            // an accent stored as its own mark, as pasted from a Mac
            "rue de l'Abbé Glatz"          => 'RUE DE L ABBE GLATZ',
            "rue de l\u{2019}Église"        => 'RUE DE L EGLISE',  // typographic apostrophe
            "12\r\nAvenue du Général"       => '12 AVENUE DU GENERAL', // Balle Jaune's address on two lines
            'Bât. A; 2e étage'             => 'BAT. A 2E ETAGE',
            "Jean\u{00A0}–\u{00A0}Pierre"     => 'JEAN - PIERRE',
            'N° 12  bis'                   => 'N 12 BIS',
            '  Dupont  '                   => 'DUPONT',
        ];
        foreach ($cases as $balleJaune => $federation) {
            self::assertSame($federation, FederationLicenceExport::text($balleJaune), $balleJaune);
        }
    }

    public function testAMemberWhoseNameIsLostInTheFoldingIsReportedMissing(): void
    {
        self::assertSame(['nom'], $this->export->missingFields(self::user(['lastname' => '李'])));
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

    public function testBlockerNamesWhyAMemberStaysOut(): void
    {
        self::assertNull($this->export->blocker(self::user(), true));
        foreach (['federale', 'jeune', 'ete'] as $kind) {
            self::assertNull($this->export->blocker(self::user(['licenceKind' => ['kind' => $kind]]), true));
        }

        // Not a member this season comes first: their licence type would be last season's, whatever else holds.
        self::assertSame('saison', $this->export->blocker(self::user(), false));
        self::assertSame('saison', $this->export->blocker(self::user(['licenceKind' => ['kind' => 'retiree'], 'sex' => '']), false));

        self::assertSame('licence', $this->export->blocker(self::user(['licenceKind' => ['kind' => 'retiree']]), true));
        self::assertSame('licence', $this->export->blocker(self::user(['licenceKind' => ['kind' => 'inconnue'], 'sex' => '']), true));
        self::assertSame('licence', $this->export->blocker(self::user(['licenceKind' => []]), true));

        self::assertSame('infos', $this->export->blocker(self::user(['birthday' => '0000-00-00']), true));
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
        self::assertSame(mb_convert_encoding(implode(';', FederationLicenceExport::COLUMNS), 'Windows-1252', 'UTF-8'), $lines[0]);
        self::assertCount(3, $lines);
        self::assertStringStartsWith('A092021;;A;JEAN-MARIE;07/03/1985;M;', $lines[1]);
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

    public function testTheFileIsWindows1252WithNothingToQuote(): void
    {
        $files = $this->export->files([self::user([
            'firstname' => 'Hélène', 'address' => "Bât. A; 2e \"étage\"\r\nRésidence l'Orée", 'city' => 'Asnières-sur-Seine',
        ])], new Season(2026));
        [$header, $row] = explode("\r\n", rtrim((string) reset($files), "\r\n"));

        // The header's accents in the encoding the extranet reads: "é" is the single byte 0xE9.
        self::assertStringContainsString("Code adh\xE9rent;Nom;Pr\xE9nom", $header);
        // The member's data is plain ASCII, on one line, with exactly the 13 separators and no quoting.
        self::assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $row);
        self::assertSame(count(FederationLicenceExport::COLUMNS) - 1, substr_count($row, ';'));
        self::assertStringContainsString(';HELENE;', $row);
        self::assertStringContainsString(';BAT. A 2E ETAGE RESIDENCE L OREE;;;92250;ASNIERES-SUR-SEINE;', $row);
    }
}
