<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

/**
 * Pure translation between a season's pricing catalogue array (the shape
 * PricingService reads — see pricing_data for the reference structure) and
 * the single-CSV export/import format used by the admin pricing editor.
 * No I/O: strings in, arrays out (and vice versa), so it's unit-testable
 * without a filesystem or a request.
 *
 * One CSV, one row per subscription price grid (a subscription with
 * couple_available produces two rows — 'individual' and 'couple', sharing
 * the same key), plus one row each for the licences, the lessons add-on,
 * the ticket pack and the summer-pack cotisation — distinguished by the
 * `type` column. Singleton rows (licence/lessons/ticket_pack/summer_pack)
 * reuse `garennois_premiere` as their one price column; `hors_commune_*`
 * and the other residence column stay blank for them.
 */
final class PricingCsvCodec
{
    private const array COLUMNS = [
        'type', 'key', 'grid', 'label', 'audience', 'couple_available', 'bj_subscription',
        'tickets', 'garennois_premiere', 'garennois_renouvellement', 'hors_commune_premiere', 'hors_commune_renouvellement',
    ];

    public function toCsv(array $catalogue): string
    {
        $fh = fopen('php://temp', 'r+');

        fputcsv($fh, self::COLUMNS, ',', '"', '');

        foreach ($catalogue['subscriptions'] as $key => $s) {
            $this->writeSubscriptionRow($fh, $key, $s, 'individual', $s['individual']);
            if (!empty($s['couple_available'])) {
                $this->writeSubscriptionRow($fh, $key, $s, 'couple', $s['couple']);
            }
        }

        foreach ($catalogue['licences'] as $key => $l) {
            fputcsv($fh, ['licence', $key, '', $l['label'], '', '', '', '', $l['price'], '', '', ''], ',', '"', '');
        }

        $summerPack = $catalogue['summer_pack'];
        fputcsv($fh, ['summer_pack', 'summer_pack', '', $summerPack['label'], '', '', '', '', $summerPack['cotisation'], '', '', ''], ',', '"', '');

        $lessons = $catalogue['lessons'];
        fputcsv($fh, ['lessons', 'lessons', '', $lessons['label'], '', '', '', '', $lessons['price'], '', '', ''], ',', '"', '');

        $tp = $catalogue['ticket_pack'];
        fputcsv($fh, ['ticket_pack', 'ticket_pack', '', $tp['label'], '', '', $tp['bj_subscription'], $tp['tickets'], $tp['price'], '', '', ''], ',', '"', '');

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }

    /** @param resource $fh */
    private function writeSubscriptionRow($fh, string $key, array $s, string $grid, array $prices): void
    {
        $garennois = $prices['garennois'] ?? null;
        $horsCommune = $prices['hors-commune'] ?? null;
        fputcsv($fh, [
            'subscription', $key, $grid, $s['label'], $s['audience'], $s['couple_available'] ? '1' : '0',
            $s['bj_subscription'], '',
            $garennois['premiere'] ?? '', $garennois['renouvellement'] ?? '',
            $horsCommune['premiere'] ?? '', $horsCommune['renouvellement'] ?? '',
        ], ',', '"', '');
    }

    /**
     * Parses a CSV back into the editable parts of a catalogue. Throws
     * InvalidArgumentException with every problem found (not just the
     * first) when the subscription key set doesn't exactly match
     * $knownSubscriptionKeys, a row is malformed, or a price isn't numeric —
     * bj_subscription values are NOT validated here (that needs a live BJ
     * lookup, done by the caller).
     *
     * @param string[] $knownSubscriptionKeys the fixed set of subscription
     *                                         keys the season being imported into already has
     * @return array{subscriptions: array, licences: array, summer_pack: array, lessons: array, ticket_pack: array}
     */
    public function fromCsv(string $csv, array $knownSubscriptionKeys): array
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);

        $header = fgetcsv($fh, escape: '');
        if ($header === false) {
            fclose($fh);
            throw new InvalidArgumentException('Fichier CSV vide.');
        }

        $errors = [];
        $subscriptions = [];
        $licences = [];
        $summerPack = null;
        $lessons = null;
        $ticketPack = null;
        $lineNo = 1;

        while (($row = fgetcsv($fh, escape: '')) !== false) {
            $lineNo++;
            if (count($row) !== count($header)) {
                $errors[] = "Ligne {$lineNo} : nombre de colonnes incorrect.";
                continue;
            }
            $r = array_combine($header, $row);

            switch ($r['type'] ?? '') {
                case 'subscription':
                    $key = $r['key'] ?? '';
                    $grid = $r['grid'] ?? '';
                    if (!in_array($grid, ['individual', 'couple'], true)) {
                        $errors[] = "Ligne {$lineNo} : grille invalide « {$grid} » (attendu individual ou couple).";
                        break;
                    }
                    [$prices, $priceErrors] = $this->parsePrices($r, $lineNo);
                    $errors = [...$errors, ...$priceErrors];
                    $subscriptions[$key] ??= [
                        'label'            => $r['label'] ?? '',
                        'audience'         => $r['audience'] ?? '',
                        'couple_available' => ($r['couple_available'] ?? '') === '1',
                        'bj_subscription'  => $r['bj_subscription'] ?? '',
                    ];
                    $subscriptions[$key][$grid] = $prices;
                    break;

                case 'licence':
                    $price = $this->parseFloat($r['garennois_premiere'] ?? '', $lineNo, $errors);
                    $licences[$r['key'] ?? ''] = ['label' => $r['label'] ?? '', 'price' => $price];
                    break;

                case 'summer_pack':
                    $cotisation = $this->parseFloat($r['garennois_premiere'] ?? '', $lineNo, $errors);
                    $summerPack = ['label' => $r['label'] ?? '', 'cotisation' => $cotisation];
                    break;

                case 'lessons':
                    $price = $this->parseFloat($r['garennois_premiere'] ?? '', $lineNo, $errors);
                    $lessons = ['label' => $r['label'] ?? '', 'price' => $price];
                    break;

                case 'ticket_pack':
                    $price = $this->parseFloat($r['garennois_premiere'] ?? '', $lineNo, $errors);
                    $ticketPack = [
                        'label'           => $r['label'] ?? '',
                        'tickets'         => (int) ($r['tickets'] ?? 0),
                        'price'           => $price,
                        'bj_subscription' => $r['bj_subscription'] ?? '',
                    ];
                    break;

                default:
                    $errors[] = "Ligne {$lineNo} : type de ligne inconnu « " . ($r['type'] ?? '') . " ».";
            }
        }
        fclose($fh);

        foreach ($subscriptions as $key => $s) {
            if (!empty($s['couple_available']) && !isset($s['couple'])) {
                $errors[] = "Ligne « couple » manquante pour {$key} (couple disponible).";
            }
            if (!isset($s['individual'])) {
                $errors[] = "Ligne « individual » manquante pour {$key}.";
            }
        }

        $missing = array_diff($knownSubscriptionKeys, array_keys($subscriptions));
        foreach ($missing as $key) {
            $errors[] = "Abonnement manquant dans le CSV : {$key}.";
        }
        $unknown = array_diff(array_keys($subscriptions), $knownSubscriptionKeys);
        foreach ($unknown as $key) {
            $errors[] = "Abonnement inconnu dans le CSV : {$key} (l'éditeur ne permet pas d'ajouter de nouvel abonnement).";
        }
        if ($summerPack === null) {
            $errors[] = 'Ligne "summer_pack" manquante.';
        }
        if ($lessons === null) {
            $errors[] = 'Ligne "lessons" manquante.';
        }
        if ($ticketPack === null) {
            $errors[] = 'Ligne "ticket_pack" manquante.';
        }

        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        return ['subscriptions' => $subscriptions, 'licences' => $licences, 'summer_pack' => $summerPack, 'lessons' => $lessons, 'ticket_pack' => $ticketPack];
    }

    /** @return array{0: array, 1: string[]} */
    private function parsePrices(array $r, int $lineNo): array
    {
        $errors = [];
        $prices = [];
        $gp = $r['garennois_premiere'] ?? '';
        $gr = $r['garennois_renouvellement'] ?? '';
        $hp = $r['hors_commune_premiere'] ?? '';
        $hr = $r['hors_commune_renouvellement'] ?? '';

        if ($gp !== '' || $gr !== '') {
            $prices['garennois'] = [
                'premiere'       => $this->parseFloat($gp, $lineNo, $errors),
                'renouvellement' => $this->parseFloat($gr, $lineNo, $errors),
            ];
        }
        if ($hp !== '' || $hr !== '') {
            $prices['hors-commune'] = [
                'premiere'       => $this->parseFloat($hp, $lineNo, $errors),
                'renouvellement' => $this->parseFloat($hr, $lineNo, $errors),
            ];
        }

        return [$prices, $errors];
    }

    private function parseFloat(string $value, int $lineNo, array &$errors): float
    {
        if ($value === '' || !is_numeric($value)) {
            $errors[] = "Ligne {$lineNo} : prix invalide « {$value} ».";
            return 0.0;
        }
        return (float) $value;
    }
}
