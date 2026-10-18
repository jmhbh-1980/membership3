<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Builds the files the club uploads to the FFSquash extranet ("Saisie de
 * licences par fichier") from the /admin/licences board, so the licences to
 * take no longer have to be typed in one by one.
 *
 * The column list and its rules are the federation's own ("Détail des
 * champs" on the Squash Pass import page): semicolon-separated, dates as
 * jj/mm/aaaa, sex as M or F, Pays left empty for France. The club uses the
 * same columns for every licence type, one file per type since each type has
 * its own import page, and never more than MAX_ROWS lines per file — the
 * extranet silently keeps only the first 100 valid lines of a file.
 *
 * Pure: takes Balle Jaune user rows as the board already holds them, never
 * writes anywhere. Registering the licences on the extranet, and then
 * clearing each member's flag on the board, stay manual.
 */
final class FederationLicenceExport
{
    public const int MAX_ROWS = 100;

    /** Licence kinds that go to the federation. A waived licence has nothing to take; an undetermined one needs checking first. */
    public const array EXPORTABLE_KINDS = ['pass', 'federale', 'jeune', 'ete'];

    public const array COLUMNS = [
        'Code structure', 'Code adhérent', 'Nom', 'Prénom', 'Date de naissance', 'Sexe', 'Adresse mail',
        'Téléphone', 'Adresse 1', 'Adresse 2', 'Adresse 3', 'Code Postal', 'Commune', 'Pays',
    ];

    public function __construct(private readonly string $structureCode)
    {
    }

    /**
     * The federation's required fields this member lacks in Balle Jaune, as
     * the labels an admin reads on the board. Empty = exportable as is.
     *
     * @return list<string>
     */
    public function missingFields(array $user): array
    {
        $missing = [];
        foreach (['lastname' => 'nom', 'firstname' => 'prénom'] as $field => $label) {
            if (trim((string) ($user[$field] ?? '')) === '') {
                $missing[] = $label;
            }
        }
        if (self::birthdate($user) === null) {
            $missing[] = 'date de naissance';
        }
        if (self::sex($user) === null) {
            $missing[] = 'sexe';
        }
        if (self::email($user) === '') {
            $missing[] = 'email';
        }
        foreach (['postalcode' => 'code postal', 'city' => 'commune'] as $field => $label) {
            if (trim((string) ($user[$field] ?? '')) === '') {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /** One line of the file, in COLUMNS order. Only meaningful once missingFields() is empty. */
    public function row(array $user): array
    {
        $country = strtoupper(trim((string) ($user['country'] ?? '')));
        return [
            $this->structureCode,
            trim((string) ($user['license_number'] ?? '')), // empty for a first licence: the federation creates the member
            trim((string) ($user['lastname'] ?? '')),
            trim((string) ($user['firstname'] ?? '')),
            self::birthdate($user)?->format('d/m/Y') ?? '',
            self::sex($user) ?? '',
            self::email($user),
            trim((string) ($user['phone1'] ?? '')) ?: trim((string) ($user['phone2'] ?? '')),
            trim((string) ($user['address'] ?? '')), // Balle Jaune holds one address line
            '',
            '',
            trim((string) ($user['postalcode'] ?? '')),
            trim((string) ($user['city'] ?? '')),
            $country === '' || $country === 'FR' ? '' : self::countryName($country),
        ];
    }

    /**
     * The files for these members, grouped by licence kind and split every
     * MAX_ROWS lines. Members are taken in the order given; anyone whose kind
     * isn't exportable or who misses a required field is left out — the
     * board shows them as such before the admin exports.
     *
     * @param array[] $users BJ user rows, each with 'licenceKind' => ['kind' => …] as the board sets it
     * @return array<string, string> file name => CSV content
     */
    public function files(array $users, Season $season): array
    {
        $byKind = [];
        foreach ($users as $user) {
            $kind = (string) ($user['licenceKind']['kind'] ?? '');
            if (in_array($kind, self::EXPORTABLE_KINDS, true) && $this->missingFields($user) === []) {
                $byKind[$kind][] = $this->row($user);
            }
        }

        $files = [];
        foreach (self::EXPORTABLE_KINDS as $kind) {
            $chunks = array_chunk($byKind[$kind] ?? [], self::MAX_ROWS);
            foreach ($chunks as $i => $rows) {
                $name = 'ffsquash-licences-' . $kind . '-' . $season->label()
                    . (count($chunks) > 1 ? '-' . ($i + 1) . '-sur-' . count($chunks) : '') . '.csv';
                $files[$name] = self::csv($rows);
            }
        }
        return $files;
    }

    /**
     * Not fputcsv(): it also quotes every value holding a space ("La
     * Garenne-Colombes"), and an importer that simply splits on semicolons
     * would keep those quotes in the member's data. Quotes only where a value
     * would otherwise break the line.
     */
    private static function csv(array $rows): string
    {
        $line = static fn (array $values): string => implode(';', array_map(
            static fn (string $v): string => strpbrk($v, ";\"\r\n") === false ? $v : '"' . str_replace('"', '""', $v) . '"',
            $values,
        )) . "\r\n";
        return $line(self::COLUMNS) . implode('', array_map($line, $rows));
    }

    private static function birthdate(array $user): ?\DateTimeImmutable
    {
        // Balle Jaune answers the MySQL zero date for a member with no birthday on file.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($user['birthday'] ?? ''));
        return $date !== false && (int) $date->format('Y') > 1900 ? $date : null;
    }

    private static function sex(array $user): ?string
    {
        return match (strtoupper((string) ($user['sex'] ?? ''))) {
            'M'      => 'M',
            'F', 'W' => 'F',
            default  => null,
        };
    }

    private static function email(array $user): string
    {
        return trim((string) ($user['email'] ?? '')) ?: trim((string) ($user['email2'] ?? ''));
    }

    /** The federation wants the country's name, Balle Jaune holds its ISO code. */
    private static function countryName(string $isoCode): string
    {
        $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-' . $isoCode, 'fr') : '';
        return $name !== '' && $name !== $isoCode ? $name : $isoCode;
    }
}
