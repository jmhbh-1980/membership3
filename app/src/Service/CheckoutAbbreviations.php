<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Shortens the fragments a cart-line label is built from, so the SumUp receipt
 * can still list every item when the full labels would not fit (see
 * CheckoutDescription, which only reaches for this when they don't).
 *
 * Fragments, not whole labels: a label is composed at runtime
 * ("Cotisation — Heures Pleines — Couple (renouvellement) (versement 1/3)"), so
 * there is no finite list of labels to map. Replacements are plain substring
 * swaps applied longest-search-first, which makes the table order-independent —
 * "Licence fédérale" wins over "Licence" wherever both are listed.
 *
 * DEFAULTS are the club's current catalogue vocabulary and ship with the code.
 * pricing_data/checkout_abbreviations.php may add to them or override any entry,
 * the same way pricing_data holds the barèmes and the invoice blurbs: catalogue
 * labels are editable in production through /admin/tarifs, so a renamed
 * subscription must be shortenable without a deploy. An entry whose search
 * string no longer appears anywhere is simply inert.
 */
final class CheckoutAbbreviations
{
    /**
     * Ordering is irrelevant here — table() sorts by search length. Keep each
     * replacement readable on a bank statement: a member should recognise what
     * they bought, so these clip words rather than encode them.
     */
    private const DEFAULTS = [
        // Subscriptions: the two long ones carry their schedule in the label.
        'Jeune (- de 19 ans) — mini-squash / école des jeunes inclus' => 'Jeune (-19 ans)',
        'Midi (11h45-13h15, lun-ven)'                                => 'Midi',

        // Line kinds.
        'Cotisation'                  => 'Cotis.',
        'Cours collectifs (adultes)'  => 'Cours coll.',
        'Formule Tickets — 5 séances' => 'Tickets ×5',
        'Pack été saison '            => 'Pack été ',

        // Licences.
        'Licence fédérale'         => 'Lic. fédérale',
        'Licence Pass'             => 'Lic. Pass',
        'Licence jeune'            => 'Lic. jeune',
        'Licence été (découverte)' => 'Lic. été',

        // Suffixes the cart appends.
        '(1ère inscription)' => '(1ère insc.)',
        '(renouvellement)'   => '(renouv.)',
        '(tarif unique)'     => '(forfait)',
        '— conjoint(e)'      => '— conj.',
        '(versement '        => '(vers. ',

        // Discount rows.
        'Réduction — statut étudiant' => 'Réduc. étudiant',
        'Réduction — code '           => 'Promo ',
    ];

    /** @var ?array<string, string> */
    private ?array $table = null;

    public function __construct(private readonly string $configDir)
    {
    }

    public function apply(string $label): string
    {
        foreach ($this->table() as $search => $replacement) {
            $label = str_replace($search, $replacement, $label);
        }

        return $label;
    }

    /** @return array<string, string> search => replacement, longest search first */
    public function table(): array
    {
        if ($this->table === null) {
            $path = $this->configDir . '/checkout_abbreviations.php';
            $overrides = is_file($path) ? require $path : [];
            $table = array_merge(self::DEFAULTS, is_array($overrides) ? $overrides : []);
            // Longest search first, so a specific fragment is consumed before
            // any shorter one it contains.
            uksort($table, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
            $this->table = $table;
        }

        return $this->table;
    }
}
