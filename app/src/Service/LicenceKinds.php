<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Db;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Which licence each member has to be registered for with the federation —
 * what the /admin/licences board filters on.
 *
 * This app, not Balle Jaune, is the source of truth for licence type (BJ has no
 * field for it; see CLAUDE.md), so it is derived the way fulfillment derives it
 * when it writes "Licence : …" into the member's BJ notes: from the member's
 * latest season in member_formulas and the order that paid for it.
 *
 *  - retiree: the licence was taken out of the cart because the member already
 *    holds one (another club) — nothing to buy, but still flagged in BJ;
 *  - ete: a Pack été (late settlement) season;
 *  - otherwise PricingService::licenceKindFor() — jeune, federale or pass.
 *
 * A member the app never registered a season for (flag set by hand in BJ, or a
 * membership settled outside the app) has no such record, so the kind is read
 * off their BJ subscription name when that name says it outright, and is
 * 'inconnue' otherwise — never a guess dressed up as a fact.
 */
final class LicenceKinds
{
    public const array LABELS = [
        'pass'     => 'Pass',
        'federale' => 'Fédérale',
        'jeune'    => 'Jeune',
        'ete'      => 'Été',
        'retiree'  => 'Licence retirée',
        'inconnue' => 'Non déterminée',
    ];

    public function __construct(
        private readonly PricingService $pricing,
        private readonly Db $db,
    ) {
    }

    /**
     * @param array[]            $bjUsers              BJ user rows (need user_id, subscription_id)
     * @param array<int, string> $subscriptionNameById BJ subscription id => name, for members the app has no season for
     * @return array<int, array{kind: string, detail: string}> keyed by bj_user_id
     */
    public function forMembers(array $bjUsers, array $subscriptionNameById): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $u): int => (int) ($u['user_id'] ?? 0),
            $bjUsers,
        ))));
        $latest = $this->latestSeasons($ids);
        $currentSeason = Season::fromDate(new DateTimeImmutable());

        $result = [];
        foreach ($bjUsers as $u) {
            $id = (int) ($u['user_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $result[$id] = isset($latest[$id])
                ? $this->fromSeason($latest[$id])
                : $this->fromBjSubscription((string) ($subscriptionNameById[(int) ($u['subscription_id'] ?? 0)] ?? ''), $currentSeason);
        }
        return $result;
    }

    /** @return array{kind: string, detail: string} */
    private function fromSeason(array $row): array
    {
        $meta = json_decode((string) ($row['meta'] ?? ''), true) ?: [];
        $isRenewal = ($row['order_kind'] ?? '') === 'renewal';
        $isPartner = $isRenewal && (int) $row['payer_id'] !== (int) $row['bj_user_id'];

        $removed = $isRenewal
            ? !empty($meta[$isPartner ? 'partnerLicenceRemoved' : 'licenceRemoved'])
            : !empty($row['licence_removed']);
        if ($removed) {
            $reason = $isRenewal
                ? (string) ($meta[$isPartner ? 'partnerLicenceRemovalReason' : 'licenceRemovalReason'] ?? '')
                : (string) ($row['licence_removal_reason'] ?? '');
            return ['kind' => 'retiree', 'detail' => $reason];
        }

        if ($isRenewal ? !empty($meta['lateSettlement']) : !empty($row['summer_pack'])) {
            return ['kind' => 'ete', 'detail' => ''];
        }

        $audience = $this->audienceOf((string) $row['subscription_type'], new Season((int) $row['season_start_year']));
        return ['kind' => $this->pricing->licenceKindFor($audience, (bool) $row['competitor']), 'detail' => ''];
    }

    /**
     * Only what the name states: the app's own Jeune formula, or a legacy
     * verbose name spelling out "Compétiteur" / "Loisir" / "Jeune".
     *
     * @return array{kind: string, detail: string}
     */
    private function fromBjSubscription(string $name, Season $season): array
    {
        $deduced = static fn (string $kind): array => ['kind' => $kind, 'detail' => 'd\'après l\'abonnement'];

        $key = $name !== '' ? $this->pricing->subscriptionKeyForBjName($name, $season) : null;
        if ($key !== null) {
            return $this->audienceOf($key, $season) === 'jeune' ? $deduced('jeune') : ['kind' => 'inconnue', 'detail' => ''];
        }

        $lower = mb_strtolower($name);
        return match (true) {
            str_contains($lower, 'jeune') && !str_contains($lower, 'école') => $deduced('jeune'),
            str_contains($lower, 'comp')                                     => $deduced('federale'), // compétiteur, with BJ typos
            str_contains($lower, 'loisir')                                   => $deduced('pass'),
            default                                                          => ['kind' => 'inconnue', 'detail' => ''],
        };
    }

    private function audienceOf(string $subscriptionType, Season $season): string
    {
        try {
            return (string) ($this->pricing->subscription($subscriptionType, $season)['audience'] ?? '');
        } catch (InvalidArgumentException) {
            // A formula since removed from the catalogue: its key still says whether it was Jeune.
            return $subscriptionType === 'jeune' ? 'jeune' : '';
        }
    }

    /**
     * Each member's latest season with the order that paid for it and, for a
     * join, their own person row on its application. Batched: MySQL 5.7 has no
     * window functions, hence the MAX() join.
     *
     * @param int[] $bjUserIds
     * @return array<int, array>
     */
    private function latestSeasons(array $bjUserIds): array
    {
        if ($bjUserIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($bjUserIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT mf.bj_user_id, mf.season_start_year, mf.subscription_type, mf.competitor,
                    o.kind AS order_kind, o.bj_user_id AS payer_id, o.meta,
                    a.summer_pack, ap.licence_removed, ap.licence_removal_reason
               FROM member_formulas mf
               JOIN (SELECT bj_user_id, MAX(season_start_year) AS latest
                       FROM member_formulas
                      WHERE bj_user_id IN ({$in})
                      GROUP BY bj_user_id) last
                 ON last.bj_user_id = mf.bj_user_id AND last.latest = mf.season_start_year
               LEFT JOIN orders o ON o.id = mf.order_id
               LEFT JOIN applications a ON a.id = o.application_id
               LEFT JOIN application_people ap ON ap.application_id = o.application_id AND ap.bj_user_id = mf.bj_user_id"
        );
        $stmt->execute($bjUserIds);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['bj_user_id']] = $row;
        }
        return $result;
    }
}
