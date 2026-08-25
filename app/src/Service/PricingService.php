<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Pure pricing engine for a season catalogue (pricing_data/pricing.<season>.php).
 * No I/O beyond loading that season's config file: everything a cart costs
 * is computed here so it can be unit-tested against the club's price table.
 *
 * Each season has its own catalogue file, named after Season::label() (e.g.
 * "pricing.2026-2027.php"). The catalogue for a given quote is picked by the
 * Season passed in — when that season's file doesn't exist yet (the next
 * season's numbers haven't been published), the most recent available file
 * is used instead, so nothing breaks while an admin drops in the new file.
 *
 * Subscription, licence and group lessons are three independently-priced
 * things: a subscription's price varies by residence and first-membership
 * status (and, for heures-pleines/heures-creuses only, by whether it's a
 * couple registration — one partner pays for both on a single line); a
 * licence's price/kind depends only on competitor status (or minority);
 * group lessons are a single flat per-person add-on.
 *
 * Prorata rule (locked with the club): members may join mid-season from
 * 1 October; the discount is (complete months elapsed since 1 September)/12,
 * applied to the cotisation and the group lessons only — licences are always
 * full price.
 *
 * An admin-issued promo code (App\Service\PromoCodeService, resolved by the
 * caller) can add a further 'discount' line on top of all the above, off the
 * cotisation + lessons subtotal only — never off licences — see quote()'s
 * $promo param.
 */
final class PricingService
{
    public const string RESIDENCE_GARENNOIS = 'garennois';
    public const string RESIDENCE_HORS_COMMUNE = 'hors-commune';

    /** Age (strictly) below which the Jeune tariff applies, at season start. */
    private const int JEUNE_MAX_AGE = 19;

    /** @var array<string, array> catalogue arrays already loaded, keyed by season label */
    private array $cache = [];

    public function __construct(
        private readonly string $configDir,
        private readonly string $garennoisZip = '92250',
    ) {
    }

    public function residenceForZip(string $zip): string
    {
        return trim($zip) === $this->garennoisZip
            ? self::RESIDENCE_GARENNOIS
            : self::RESIDENCE_HORS_COMMUNE;
    }

    /** Jeune tariff: under 19 at season start. */
    public function isJeune(DateTimeImmutable $birthdate, Season $season): bool
    {
        return $this->ageAt($birthdate, $season->start()) < self::JEUNE_MAX_AGE;
    }

    /** Legal minor (guardian + health attestation required): under 18 today. */
    public function isMinor(DateTimeImmutable $birthdate, DateTimeImmutable $today): bool
    {
        return $this->ageAt($birthdate, $today) < 18;
    }

    /**
     * @return array<string, array> subscriptions available for a residence.
     *   $midiResidencyOverride includes 'midi' for a Hors-commune caller —
     *   the seam both the per-application admin exception and the renewal
     *   auto-grandfather path plug into.
     */
    public function subscriptionsFor(string $residence, Season $season, bool $midiResidencyOverride = false): array
    {
        return array_filter(
            $this->catalogueFor($season)['subscriptions'],
            fn (array $s, string $key) => isset($s['individual'][$residence])
                || ($key === 'midi' && $residence === self::RESIDENCE_HORS_COMMUNE && $midiResidencyOverride),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public function subscription(string $key, Season $season): array
    {
        $catalogue = $this->catalogueFor($season);
        if (!isset($catalogue['subscriptions'][$key])) {
            throw new InvalidArgumentException("Abonnement inconnu : {$key}");
        }
        return $catalogue['subscriptions'][$key];
    }

    /**
     * Reverse of subscription()['bj_subscription']: which catalogue key (if
     * any) this exact BJ subscription name belongs to — an exact match, not a
     * guess, for the app's own simplified "_"-prefixed subscription names.
     */
    public function subscriptionKeyForBjName(string $bjSubscriptionName, Season $season): ?string
    {
        foreach ($this->catalogueFor($season)['subscriptions'] as $key => $s) {
            if ($s['bj_subscription'] === $bjSubscriptionName) {
                return $key;
            }
        }
        return null;
    }

    /** Licence kind for a person: 'jeune' for the Jeune audience, else 'federale'/'pass' by competitor status. */
    public function licenceKindFor(string $audience, bool $competitor): string
    {
        if ($audience === 'jeune') {
            return 'jeune';
        }
        return $competitor ? 'federale' : 'pass';
    }

    /**
     * Whether a season's pricing has actually been published (exact file
     * match, no fallback) — the explicit signal for "is this season open
     * for renewal", as opposed to catalogueFor()'s tolerant fallback used
     * for resolving prices once a season is already known to be in play.
     */
    public function hasCatalogue(Season $season): bool
    {
        return is_file($this->configDir . '/pricing.' . $season->label() . '.php');
    }

    /**
     * Builds the cart for a yearly membership.
     *
     * @param string $subscriptionKey key in the catalogue ('heures-pleines' | 'heures-creuses' | 'midi' | 'jeune')
     * @param string $residence       'garennois' | 'hors-commune'
     * @param bool   $premiere        true = 1ère inscription, false = renouvellement
     * @param ?DateTimeImmutable $joinDate mid-season join date (null = season start, full price)
     * @param Season $season          the season being purchased
     * @param bool   $isCouple        one partner pays for both — a single cotisation line from the
     *                                'couple' price grid (only offered for heures-pleines/heures-creuses)
     * @param array<int, array{competitor: bool, licenceRemoved: bool}> $people
     *                                1 entry for an individual registration, 2 for a couple (index 0 =
     *                                registering member, index 1 = partner). Each produces its own
     *                                independent licence line unless that person's licenceRemoved is true.
     *                                Jeune subscriptions force the licence kind regardless of competitor.
     * @param int    $lessonsCount    number of adult group-lesson enrolments (0-2; couples may take 2)
     * @param bool   $midiResidencyOverride waives Midi's Garennois-only restriction for a Hors-commune
     *                                caller, charged at the Garennois price (no separate price bucket)
     * @param bool   $summerPack      "Pack été": a member/joiner catching the tail end of an almost-over
     *                                season pays the catalogue's flat summer_pack cotisation (never
     *                                prorated, regardless of joinDate) instead of the price-table
     *                                cotisation, and every person's licence is forced to the flat 'ete'
     *                                kind regardless of competitor status. Solo only — group lessons and
     *                                couple registration aren't offered alongside it; passing a non-zero
     *                                lessonsCount or isCouple: true throws.
     * @param ?array{code: string, kind: string, value: float} $promo an already-resolved promo code
     *                                (PromoCodeService::resolve() — this method never looks one up
     *                                itself, to stay DB-free): 'percent' (0-100) or 'fixed' (euros) off
     *                                the cotisation + lessons subtotal only, added as a negative
     *                                'discount' line. Licences are never discounted — same rule as the
     *                                prorata discount above — so a fixed value is capped at that
     *                                narrower subtotal rather than the full total.
     */
    public function quote(
        string $subscriptionKey,
        string $residence,
        bool $premiere,
        Season $season,
        ?DateTimeImmutable $joinDate = null,
        bool $isCouple = false,
        array $people = [['competitor' => false, 'licenceRemoved' => false]],
        int $lessonsCount = 0,
        bool $midiResidencyOverride = false,
        bool $summerPack = false,
        ?array $promo = null,
    ): Quote {
        $catalogue = $this->catalogueFor($season);
        $subscription = $this->subscription($subscriptionKey, $season);

        if ($isCouple && empty($subscription['couple_available'])) {
            throw new InvalidArgumentException(
                "L'abonnement « {$subscription['label']} » n'est pas disponible en couple."
            );
        }
        $expectedPeople = $isCouple ? 2 : 1;
        if (count($people) !== $expectedPeople) {
            throw new InvalidArgumentException("Nombre de personnes invalide pour cette formule ({$expectedPeople} attendu(s)).");
        }

        $grid = $isCouple ? $subscription['couple'] : $subscription['individual'];
        $priceResidence = ($subscriptionKey === 'midi' && $residence === self::RESIDENCE_HORS_COMMUNE && $midiResidencyOverride)
            ? self::RESIDENCE_GARENNOIS
            : $residence;
        if (!isset($grid[$priceResidence])) {
            throw new InvalidArgumentException(
                "L'abonnement « {$subscription['label']} » n'est pas ouvert aux résidents {$residence}."
            );
        }

        if ($subscription['audience'] === 'jeune' && $lessonsCount > 0) {
            throw new InvalidArgumentException(
                'Les cours sont inclus dans l\'abonnement Jeune : pas de ligne cours séparée.'
            );
        }
        if ($summerPack && $lessonsCount > 0) {
            throw new InvalidArgumentException(
                'Les cours collectifs ne sont pas proposés avec le Pack été.'
            );
        }
        if ($summerPack && $isCouple) {
            throw new InvalidArgumentException(
                'Le Pack été n\'est pas proposé aux couples.'
            );
        }
        $maxLessons = $isCouple ? 2 : 1;
        if ($lessonsCount < 0 || $lessonsCount > $maxLessons) {
            throw new InvalidArgumentException("Nombre de cours collectifs invalide : {$lessonsCount}");
        }

        $months = $joinDate !== null && $joinDate >= $season->start()->modify('+1 month')
            ? $season->elapsedMonths($joinDate)
            : 0;
        $factor = (12 - $months) / 12;
        // The summer-pack cotisation is a flat one-time fee, never prorated —
        // regardless of what joinDate/factor were computed above (a July/August
        // join's season is the current, about-to-end one, so factor would
        // otherwise shrink it to almost nothing).
        $cotisationFactor = $summerPack ? 1.0 : $factor;

        $lines = [];

        $base = $summerPack ? $catalogue['summer_pack']['cotisation'] : (float) $grid[$priceResidence][$premiere ? 'premiere' : 'renouvellement'];
        $cotisationLabel = $summerPack
            ? 'Cotisation — Pack été saison ' . $season->label() . ' (tarif unique)'
            : 'Cotisation — ' . $subscription['label'] . ($isCouple ? ' — Couple' : '')
                . ' (' . ($premiere ? '1ère inscription' : 'renouvellement') . ')';
        $lines[] = new CartLine('cotisation', $cotisationLabel, round($base * $cotisationFactor, 2), $base);

        foreach ($people as $index => $person) {
            if (!empty($person['licenceRemoved'])) {
                continue;
            }
            $kind = $summerPack ? 'ete' : $this->licenceKindFor($subscription['audience'], (bool) ($person['competitor'] ?? false));
            $licence = $catalogue['licences'][$kind];
            $label = $licence['label'] . ($isCouple ? ($index === 0 ? ' — vous' : ' — conjoint(e)') : '');
            $lines[] = new CartLine(
                'licence',
                $label,
                round($licence['price'], 2),
                round($licence['price'], 2),
                removable: true,
                personIndex: $isCouple ? $index + 1 : null,
            );
        }

        if ($lessonsCount > 0) {
            $lesson = $catalogue['lessons'];
            $lineBase = $lesson['price'] * $lessonsCount;
            $lines[] = new CartLine(
                'lessons',
                $lesson['label'] . ($lessonsCount > 1 ? " × {$lessonsCount}" : ''),
                round($lineBase * $factor, 2),
                round($lineBase, 2),
            );
        }

        if ($promo !== null) {
            // Licences are never discounted — same rule as the prorata discount
            // above, which already skips them. A fixed discount is capped at
            // this narrower subtotal too, so it can never eat into licence money.
            $discountable = array_filter($lines, fn (CartLine $l) => $l->type !== 'licence');
            $discountableSubtotal = round(array_sum(array_map(fn (CartLine $l) => $l->amount, $discountable)), 2);
            $discount = $promo['kind'] === 'percent'
                ? round($discountableSubtotal * $promo['value'] / 100, 2)
                : min($promo['value'], $discountableSubtotal);
            if ($discount > 0) {
                $lines[] = new CartLine('discount', 'Réduction — code ' . $promo['code'], -$discount, -$discount);
            }
        }

        return new Quote($lines, $months, $subscriptionKey, $subscription['bj_subscription'], $isCouple);
    }

    /** @return array{label:string,tickets:int,price:?float,bj_subscription:string} */
    public function ticketPack(Season $season): array
    {
        return $this->catalogueFor($season)['ticket_pack'];
    }

    /** @return array{label:string,price:float} */
    public function licenceInfo(string $kind, Season $season): array
    {
        $licence = $this->catalogueFor($season)['licences'][$kind];
        return ['label' => $licence['label'], 'price' => (float) $licence['price']];
    }

    private function ageAt(DateTimeImmutable $birthdate, DateTimeImmutable $when): int
    {
        return $birthdate->diff($when)->y;
    }

    /**
     * Loads (and caches) the catalogue for a season, falling back to the
     * most recent available file when that exact season hasn't been
     * published yet — e.g. mid-August, before the admin has dropped in next
     * season's pricing.<label>.php.
     */
    private function catalogueFor(Season $season): array
    {
        return $this->cache[$season->label()] ??= require $this->resolvePath($season->label());
    }

    private function resolvePath(string $label): string
    {
        $exact = $this->configDir . '/pricing.' . $label . '.php';
        if (is_file($exact)) {
            return $exact;
        }

        $files = glob($this->configDir . '/pricing.*.php') ?: [];
        if ($files === []) {
            throw new \RuntimeException("Aucun barème tarifaire trouvé dans {$this->configDir}.");
        }
        sort($files); // "pricing.2025-2026.php" < "pricing.2026-2027.php" — lexical order matches chronological order
        return end($files);
    }
}
