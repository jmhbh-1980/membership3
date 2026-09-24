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
 * "Residence" here always means the *pricing* residence — the grid a person is
 * read at — never where they actually live. The two are the same for almost
 * everyone (residenceForZip() derives the factual one from the postcode), but
 * an admin can grant an exception that reads a non-resident at the Garennois
 * grid, or the reverse; pricingResidence() is where the two meet, and the
 * residence_exceptions table (renewals) / applications.pricing_residence
 * (joins) hold the grant. Since Midi is the only subscription with no
 * hors-commune bucket, an exception both unlocks it and prices it at the
 * Garennois rate — which is exactly what the old, Midi-only
 * midiResidencyOverride flag used to do on its own.
 *
 * Everything factual — the justificatif-de-domicile requirement, the admin
 * badges, the renewal campaign's Garennois-first ordering, the residence
 * printed on an invoice — must keep using the factual residence instead.
 *
 * Prorata rule (locked with the club): members may join mid-season from
 * 1 October; the discount is (complete months elapsed since 1 September)/12,
 * applied to the cotisation and the group lessons only — licences are always
 * full price.
 *
 * An admin-issued promo code (App\Service\PromoCodeService, resolved by the
 * caller) can add a further 'discount' line on top of all the above, off the
 * cotisation + lessons subtotal only — never off licences — see quote()'s
 * $promo param. A student discount (certificat de scolarité, admin-approved,
 * individual subscriptions only) uses the same discount line and the same
 * exclusions, via $studentDiscount — mutually exclusive with $promo.
 */
final class PricingService
{
    public const string RESIDENCE_GARENNOIS = 'garennois';
    public const string RESIDENCE_HORS_COMMUNE = 'hors-commune';

    /**
     * Formule Tickets as a join choice: the catalogue's ticket_pack sold on its
     * own — no cotisation, no licence, no prorata, never couple or lessons, and
     * no season end (Balle Jaune holds a ticket member with no membership dates).
     * Deliberately not a key of the 'subscriptions' catalogue, so renewals, the
     * residence grids and everything built on subscriptionsFor() never see it;
     * joinFormula() and quote() are the two places that accept it.
     */
    public const string TICKETS = 'tickets';

    /** Age (strictly) below which the Jeune tariff applies, at season start. */
    private const int JEUNE_MAX_AGE = 19;

    /** Off the cotisation + lessons subtotal, same exclusions as a promo code. */
    private const float STUDENT_DISCOUNT_PERCENT = 50.0;

    /** @var array<string, array> catalogue arrays already loaded, keyed by season label */
    private array $cache = [];

    public function __construct(
        private readonly string $configDir,
        private readonly string $garennoisZip = '92250',
    ) {
    }

    /** Where someone actually lives, from their postcode. Never overridable. */
    public function residenceForZip(string $zip): string
    {
        return trim($zip) === $this->garennoisZip
            ? self::RESIDENCE_GARENNOIS
            : self::RESIDENCE_HORS_COMMUNE;
    }

    /**
     * The grid a person is priced at: the admin-granted exception when there is
     * one, else where they actually live. Pure on purpose — the caller looks the
     * grant up (residence_exceptions for a member, applications.pricing_residence
     * for an applicant) and passes it in as $override, empty string for none.
     */
    public static function pricingResidence(string $residence, string $override): string
    {
        return $override !== '' ? $override : $residence;
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
     * @return array<string, array> subscriptions available at a pricing
     *   residence — i.e. the ones that have a price bucket for it. Midi is the
     *   only Garennois-only formula, so a granted exception makes it appear
     *   here for a non-resident with no special case needed.
     */
    public function subscriptionsFor(string $pricingResidence, Season $season): array
    {
        return array_filter(
            $this->catalogueFor($season)['subscriptions'],
            fn (array $s) => isset($s['individual'][$pricingResidence]),
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
     * What a join application is on: a catalogue subscription, or the ticket
     * formula described in the same shape (label, audience, couple_available,
     * bj_subscription) so the wizard, cart and invoice can read either alike.
     * Its audience is TICKETS, never 'jeune' or 'adulte' — nothing licence- or
     * lessons-related applies to it.
     */
    public function joinFormula(string $key, Season $season): array
    {
        if ($key !== self::TICKETS) {
            return $this->subscription($key, $season);
        }
        $pack = $this->ticketPack($season);
        return [
            'label'            => $pack['label'],
            'audience'         => self::TICKETS,
            'couple_available' => false,
            'bj_subscription'  => $pack['bj_subscription'],
        ];
    }

    /** Whether this season's ticket pack can be sold as a join — it needs a price and a BJ subscription to put the member on. */
    public function ticketJoinAvailable(Season $season): bool
    {
        $pack = $this->ticketPack($season);
        return (float) ($pack['price'] ?? 0) > 0 && trim((string) ($pack['bj_subscription'] ?? '')) !== '';
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
     * @param string $subscriptionKey key in the catalogue ('heures-pleines' | 'heures-creuses' | 'midi' | 'jeune'),
     *                                or TICKETS for a Formule Tickets join (see ticketQuote())
     * @param string $pricingResidence 'garennois' | 'hors-commune' — the grid to read, which is
     *                                where the person lives unless an admin granted an exception
     *                                (see pricingResidence()). Not necessarily where they live.
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
     * @param bool   $summerPack      "Pack été": a member/joiner catching the tail end of an almost-over
     *                                season pays the catalogue's flat summer_pack cotisation (never
     *                                prorated, regardless of joinDate) instead of the price-table
     *                                cotisation, and every person's licence is forced to the flat 'ete'
     *                                kind regardless of competitor status. Solo only — group lessons and
     *                                couple registration aren't offered alongside it; passing a non-zero
     *                                lessonsCount or isCouple: true throws.
     * @param bool   $studentDiscount 50% off the cotisation + lessons subtotal (never licences) for a
     *                                member/applicant who provided a certificat de scolarité, approved
     *                                by an admin — individual (non-couple) subscriptions only, and
     *                                mutually exclusive with $promo (both throw if combined; enforced at
     *                                the controller/UI level too, this is the backstop). Also not offered
     *                                on the Jeune audience — that tier is already the age-based discount
     *                                a minor is on, so a certificat de scolarité there would just double
     *                                up on the same fact (still in school) rather than reflect anything new.
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
        string $pricingResidence,
        bool $premiere,
        Season $season,
        ?DateTimeImmutable $joinDate = null,
        bool $isCouple = false,
        array $people = [['competitor' => false, 'licenceRemoved' => false]],
        int $lessonsCount = 0,
        bool $summerPack = false,
        bool $studentDiscount = false,
        ?array $promo = null,
    ): Quote {
        if ($subscriptionKey === self::TICKETS) {
            return $this->ticketQuote($season, $isCouple, $people, $lessonsCount, $summerPack, $studentDiscount, $promo);
        }

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
        if (!isset($grid[$pricingResidence])) {
            throw new InvalidArgumentException(
                "L'abonnement « {$subscription['label']} » n'est pas ouvert aux résidents {$pricingResidence}."
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
        if ($studentDiscount && $isCouple) {
            throw new InvalidArgumentException(
                "La réduction étudiant n'est pas proposée aux couples."
            );
        }
        // Jeune is already the age-based discounted tier a minor is on — a second
        // 50% "student" discount on top double-dips on the same fact (still in
        // school). A guardian reading "étudiant(e)" literally for a lycéen(ne)
        // is the common case this guards against; see admin-panel discussion.
        if ($studentDiscount && $subscription['audience'] === 'jeune') {
            throw new InvalidArgumentException(
                "La réduction étudiant n'est pas proposée avec l'abonnement Jeune."
            );
        }
        if ($studentDiscount && $promo !== null) {
            throw new InvalidArgumentException(
                'La réduction étudiant et un code promo ne peuvent pas être combinés.'
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

        $base = $summerPack ? $catalogue['summer_pack']['cotisation'] : (float) $grid[$pricingResidence][$premiere ? 'premiere' : 'renouvellement'];
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

        if ($studentDiscount || $promo !== null) {
            // Licences are never discounted — same rule as the prorata discount
            // above, which already skips them. A fixed discount is capped at
            // this narrower subtotal too, so it can never eat into licence money.
            $discountable = array_filter($lines, fn (CartLine $l) => $l->type !== 'licence');
            $discountableSubtotal = round(array_sum(array_map(fn (CartLine $l) => $l->amount, $discountable)), 2);
            if ($studentDiscount) {
                $discount = round($discountableSubtotal * self::STUDENT_DISCOUNT_PERCENT / 100, 2);
                $label = 'Réduction — statut étudiant';
            } else {
                $discount = $promo['kind'] === 'percent'
                    ? round($discountableSubtotal * $promo['value'] / 100, 2)
                    : min($promo['value'], $discountableSubtotal);
                $label = 'Réduction — code ' . $promo['code'];
            }
            if ($discount > 0) {
                $lines[] = new CartLine('discount', $label, -$discount, -$discount);
            }
        }

        return new Quote($lines, $months, $subscriptionKey, $subscription['bj_subscription'], $isCouple);
    }

    /** @return array{label:string,tickets:int,price:?float,bj_subscription:string} */
    public function ticketPack(Season $season): array
    {
        return $this->catalogueFor($season)['ticket_pack'];
    }

    /**
     * A Formule Tickets join: the pack at its catalogue price and nothing else.
     * Everything quote() can add on top of a subscription is refused rather than
     * ignored — a couple, lessons, the summer pack — and so are both discounts,
     * which only ever apply to a cotisation + lessons subtotal this has none of.
     *
     * @param array<int, array{competitor: bool, licenceRemoved: bool}> $people
     */
    private function ticketQuote(
        Season $season,
        bool $isCouple,
        array $people,
        int $lessonsCount,
        bool $summerPack,
        bool $studentDiscount,
        ?array $promo,
    ): Quote {
        if (!$this->ticketJoinAvailable($season)) {
            throw new InvalidArgumentException('La formule tickets n\'est pas proposée cette saison.');
        }
        if ($isCouple || count($people) !== 1) {
            throw new InvalidArgumentException('La formule tickets est individuelle.');
        }
        if ($lessonsCount !== 0) {
            throw new InvalidArgumentException('Les cours collectifs ne sont pas proposés avec la formule tickets.');
        }
        if ($summerPack) {
            throw new InvalidArgumentException('La formule tickets ne se combine pas avec le Pack été.');
        }
        if ($studentDiscount || $promo !== null) {
            throw new InvalidArgumentException('Aucune réduction ne s\'applique à la formule tickets.');
        }

        $pack = $this->ticketPack($season);
        $price = round((float) $pack['price'], 2);
        return new Quote(
            [new CartLine('tickets', $pack['label'], $price, $price)],
            0,
            self::TICKETS,
            $pack['bj_subscription'],
        );
    }

    /**
     * Prices a standalone, mid-season group-lessons add-on for a member who
     * already renewed without it — same prorata rule as quote()'s lessons
     * line, anchored on $asOf (the day they add it) instead of a join date.
     *
     * @return array{label:string, amount:float, baseAmount:float}
     */
    public function lessonAddOn(Season $season, DateTimeImmutable $asOf): array
    {
        $lesson = $this->catalogueFor($season)['lessons'];
        $months = $asOf >= $season->start()->modify('+1 month') ? $season->elapsedMonths($asOf) : 0;
        $factor = (12 - $months) / 12;
        return [
            'label'      => $lesson['label'],
            'baseAmount' => round($lesson['price'], 2),
            'amount'     => round($lesson['price'] * $factor, 2),
        ];
    }

    /** @return string[] licence kinds this season's catalogue defines ('pass', 'federale', 'jeune', 'ete') */
    public function licenceKinds(Season $season): array
    {
        return array_keys($this->catalogueFor($season)['licences']);
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
