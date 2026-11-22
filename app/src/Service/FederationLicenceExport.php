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
 * Text goes in the way the federation keeps it — upper case, no accent, no
 * apostrophe — in a Windows-1252 file; see text() and csv() for why.
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

    /**
     * What text() turns into plain ASCII, applied after upper-casing (so upper
     * case only): Latin-1 and Latin Extended-A letters, which cover every name
     * and street the club has met, and the typographic punctuation a phone or
     * a word processor puts in instead of ASCII. A table rather than intl's
     * transliteration, which the production PHP isn't required to have.
     */
    private const array FOLD = [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Ā' => 'A', 'Ă' => 'A', 'Ą' => 'A',
        'Æ' => 'AE', 'Ç' => 'C', 'Ć' => 'C', 'Ĉ' => 'C', 'Ċ' => 'C', 'Č' => 'C', 'Ð' => 'D', 'Ď' => 'D', 'Đ' => 'D',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ē' => 'E', 'Ĕ' => 'E', 'Ė' => 'E', 'Ę' => 'E', 'Ě' => 'E',
        'Ĝ' => 'G', 'Ğ' => 'G', 'Ġ' => 'G', 'Ģ' => 'G', 'Ĥ' => 'H', 'Ħ' => 'H',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ĩ' => 'I', 'Ī' => 'I', 'Ĭ' => 'I', 'Į' => 'I', 'İ' => 'I',
        'Ĳ' => 'IJ', 'Ĵ' => 'J', 'Ķ' => 'K', 'Ĺ' => 'L', 'Ļ' => 'L', 'Ľ' => 'L', 'Ŀ' => 'L', 'Ł' => 'L',
        'Ñ' => 'N', 'Ń' => 'N', 'Ņ' => 'N', 'Ň' => 'N', 'Ŋ' => 'N',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Ō' => 'O', 'Ŏ' => 'O', 'Ő' => 'O',
        'Œ' => 'OE', 'Ŕ' => 'R', 'Ŗ' => 'R', 'Ř' => 'R', 'Ś' => 'S', 'Ŝ' => 'S', 'Ş' => 'S', 'Š' => 'S', 'Ș' => 'S',
        'ẞ' => 'SS', 'Ţ' => 'T', 'Ť' => 'T', 'Ŧ' => 'T', 'Ț' => 'T', 'Þ' => 'TH',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ũ' => 'U', 'Ū' => 'U', 'Ŭ' => 'U', 'Ů' => 'U', 'Ű' => 'U', 'Ų' => 'U',
        'Ŵ' => 'W', 'Ý' => 'Y', 'Ÿ' => 'Y', 'Ŷ' => 'Y', 'Ź' => 'Z', 'Ż' => 'Z', 'Ž' => 'Z',
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{02BC}" => "'", "\u{00B4}" => "'", "\u{2032}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{00AB}" => '"', "\u{00BB}" => '"',
        "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
        "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ', "\u{2026}" => '...',
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
        // Checked on what the file will hold: a name made only of what text() drops would arrive empty.
        foreach (['lastname' => 'nom', 'firstname' => 'prénom'] as $field => $label) {
            if (self::text((string) ($user[$field] ?? '')) === '') {
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
            if (self::text((string) ($user[$field] ?? '')) === '') {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /**
     * Why this member stays out of the file, or null when they can go in —
     * the one rule every page offering the export applies:
     *  - 'saison'  not a member this season: their licence type would be last
     *              season's, read into a file named for this one;
     *  - 'licence' waived, or a type nobody has determined yet;
     *  - 'infos'   something the extranet requires is missing in Balle Jaune.
     *
     * @param array $user BJ user row with 'licenceKind' => ['kind' => …]
     */
    public function blocker(array $user, bool $coveredThisSeason): ?string
    {
        if (!$coveredThisSeason) {
            return 'saison';
        }
        if (!in_array((string) ($user['licenceKind']['kind'] ?? ''), self::EXPORTABLE_KINDS, true)) {
            return 'licence';
        }
        return $this->missingFields($user) === [] ? null : 'infos';
    }

    /** One line of the file, in COLUMNS order. Only meaningful once missingFields() is empty. */
    public function row(array $user): array
    {
        $country = strtoupper(trim((string) ($user['country'] ?? '')));
        return [
            $this->structureCode,
            self::plain((string) ($user['license_number'] ?? '')), // empty for a first licence: the federation creates the member
            self::text((string) ($user['lastname'] ?? '')),
            self::text((string) ($user['firstname'] ?? '')),
            self::birthdate($user)?->format('d/m/Y') ?? '',
            self::sex($user) ?? '',
            self::plain(self::email($user)),
            self::plain(trim((string) ($user['phone1'] ?? '')) ?: (string) ($user['phone2'] ?? '')),
            // Balle Jaune has one address field, sometimes on several lines: text() joins them with a
            // space, which is how the federation holds such an address ("12\nAvenue…" → "12 AVENUE…").
            self::text((string) ($user['address'] ?? '')),
            '',
            '',
            self::text((string) ($user['postalcode'] ?? '')),
            self::text((string) ($user['city'] ?? '')),
            $country === '' || $country === 'FR' ? '' : self::text(self::countryName($country)),
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
     * A name, address or town the way the federation keeps it. Its own data
     * (a licence export compared field by field with Balle Jaune for 163 of
     * the club's members, Nov 2026) holds them in upper case with no accent
     * and no apostrophe — "HELENE", "ASNIERES-SUR-SEINE", a space where Balle
     * Jaune has "l'" — and shows what an accent turns into when one does get
     * through: the extranet reads the bytes as Latin-1, so a UTF-8 "é" becomes
     * "Ã©" and is stored as "A&COPY;" ("SA&AMP;COPY;BASTIEN", "ASNIA RES").
     *
     * So: upper case, letters folded to ASCII (FOLD, then any accent left as a
     * separate mark — "e" + U+0301, as text pasted on a Mac holds it), hyphens
     * kept, apostrophes, quotes, the separator and line breaks turned into
     * spaces, anything else outside ASCII dropped, spaces collapsed.
     */
    public static function text(string $value): string
    {
        $value = strtr(mb_strtoupper($value, 'UTF-8'), self::FOLD);
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value; // a letter FOLD misses, as letter + mark
        }
        $value = preg_replace('/\p{M}+/u', '', $value) ?? $value;
        $value = preg_replace('/[\'`";\x00-\x1F\x7F]/', ' ', $value) ?? $value;
        $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? $value;
        return trim(preg_replace('/ {2,}/', ' ', $value) ?? $value);
    }

    /** A value ASCII by nature (email, phone, licence number): only what would break the line goes. */
    private static function plain(string $value): string
    {
        return trim(preg_replace('/[";\x00-\x1F\x7F]+/', ' ', $value) ?? $value);
    }

    /**
     * Semicolons and nothing else: every value has been through text() or
     * plain(), so none holds a separator, quote or line break to escape — and
     * an importer that simply splits on semicolons would keep any quoting in
     * the member's data.
     *
     * Windows-1252, the encoding the extranet reads (see text()). The values
     * are ASCII, the same in any encoding, so this only spells the header's
     * "Code adhérent", "Prénom" and "Téléphone" the way the federation reads
     * them — and lets Excel open the file without garbling them.
     */
    private static function csv(array $rows): string
    {
        $line = static fn (array $values): string => implode(';', $values) . "\r\n";
        return mb_convert_encoding($line(self::COLUMNS) . implode('', array_map($line, $rows)), 'Windows-1252', 'UTF-8');
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
