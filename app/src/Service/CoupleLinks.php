<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\BalleJauneException;
use App\Support\Db;

/**
 * Who the other half of a couple is, for whatever the admin is looking at.
 *
 * A couple is one registration paid once for two people, but almost everything
 * the app stores hangs off one of them: a renewal order carries only the payer's
 * bj_user_id, a change request only the requester's, a BJ member row only its own
 * custom3. Left alone, every admin page shows one half and silently hides the
 * other. This is the one place that knows where each record keeps its partner:
 *
 *  - a join order: its application, whose position 2 person is the partner
 *    (bj_user_id stays 0 on both until fulfillment creates their accounts);
 *  - a renewal order: meta.partnerBjUserId, frozen at checkout, with the
 *    member_formulas row fulfillment wrote for the partner as a fallback;
 *  - a member: the same custom2/custom3-then-member_formulas resolution the
 *    renewal flow itself uses (RenewalService::resolveCoupleStatus()), so the
 *    admin sees the pairing the member's next renewal will actually use.
 *
 * Read-only, and batched: one SQL query per record type and at most one Balle
 * Jaune call for every name a page needs, whatever the number of rows. A BJ
 * outage leaves names as "adhérent #id" rather than failing the page.
 *
 * A person is ['name' => 'NOM Prénom', 'bjUserId' => int]; bjUserId is 0 for an
 * applicant not fulfilled yet, who has no profile page to link to.
 */
final class CoupleLinks
{
    public function __construct(
        private readonly BalleJauneClient $bj,
        private readonly RenewalService $renewals,
        private readonly Db $db,
    ) {
    }

    /**
     * The two halves of every couple order in $orders. Individual orders (and
     * kinds that are never for a couple: credits, lessons) are simply absent.
     *
     * 'partner' is null on a couple order that names no partner — the
     * first-time-couple bug of 2026-09 left exactly that on three renewals, and
     * a fulfilled one means the partner was never renewed, so the pages show it
     * rather than quietly rendering the order as individual.
     *
     * @param array[]               $orders     raw `orders` rows
     * @param array<int, string>    $knownNames bjUserId => display name the caller already holds
     * @return array<int, array{payer: array{name: string, bjUserId: int}, partner: ?array{name: string, bjUserId: int}}>
     *         keyed by order id
     */
    public function forOrders(array $orders, array $knownNames = []): array
    {
        $joinApplicationIds = [];
        $renewals = [];
        foreach ($orders as $order) {
            if ($order['kind'] === 'join' && $order['application_id'] !== null) {
                $joinApplicationIds[(int) $order['application_id']] = true;
            } elseif ($order['kind'] === 'renewal') {
                $meta = json_decode((string) ($order['meta'] ?? '{}'), true) ?: [];
                if (!empty($meta['isCouple'])) {
                    $renewals[(int) $order['id']] = [
                        'payer'   => (int) $order['bj_user_id'],
                        'partner' => (int) ($meta['partnerBjUserId'] ?? 0),
                    ];
                }
            }
        }

        $applications = $this->coupleApplications(array_keys($joinApplicationIds));

        // Orders fulfilled before their meta carried the partner still have
        // the member_formulas row fulfillment wrote for them.
        $missing = array_keys(array_filter($renewals, static fn (array $r): bool => $r['partner'] === 0));
        foreach ($this->partnersFromFormulas($missing) as $orderId => $partnerId) {
            if ($partnerId !== $renewals[$orderId]['payer']) {
                $renewals[$orderId]['partner'] = $partnerId;
            }
        }

        $ids = [];
        foreach ($renewals as $r) {
            $ids[] = $r['payer'];
            $ids[] = $r['partner'];
        }
        $names = $this->names($ids, $knownNames);

        $result = [];
        foreach ($orders as $order) {
            $orderId = (int) $order['id'];
            if (isset($renewals[$orderId])) {
                $r = $renewals[$orderId];
                $result[$orderId] = [
                    'payer'   => $this->person($r['payer'], $names),
                    'partner' => $r['partner'] > 0 ? $this->person($r['partner'], $names) : null,
                ];
            } elseif ($order['kind'] === 'join' && isset($applications[(int) $order['application_id']])) {
                $result[$orderId] = $applications[(int) $order['application_id']];
            }
        }
        return $result;
    }

    /** forOrders() for a single order; null when it isn't a couple order. */
    public function forOrder(array $order): ?array
    {
        return $this->forOrders([$order])[(int) $order['id']] ?? null;
    }

    /**
     * Each member's current partner, keyed by bj_user_id. Only members in a
     * couple appear; the value is null when they are flagged as a couple with
     * nobody linked (custom2 set, custom3 empty) — a gap an admin should fill
     * in Balle Jaune before the pair renews.
     *
     * @param array[] $bjUsers BJ user rows (need user_id, custom2, custom3; names reused when present)
     * @return array<int, ?array{name: string, bjUserId: int}>
     */
    public function forMembers(array $bjUsers): array
    {
        $knownNames = [];
        $unwritten = [];
        foreach ($bjUsers as $u) {
            $id = (int) ($u['user_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (isset($u['lastname']) || isset($u['firstname'])) {
                $knownNames[$id] = self::displayName($u);
            }
            if (($u['custom2'] ?? '') !== '1') {
                $unwritten[] = $id;
            }
        }
        $latest = $this->latestFormulas($unwritten);

        $partnerIds = [];
        foreach ($bjUsers as $u) {
            $id = (int) ($u['user_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $status = $this->renewals->resolveCoupleStatus($u, $latest[$id] ?? null, null);
            if ($status['isCouple']) {
                $partnerIds[$id] = $status['partnerBjUserId'];
            }
        }

        $names = $this->names(array_values($partnerIds), $knownNames);
        $result = [];
        foreach ($partnerIds as $id => $partnerId) {
            $result[$id] = $partnerId > 0 && $partnerId !== $id ? $this->person($partnerId, $names) : null;
        }
        return $result;
    }

    /**
     * forMembers() for one member; the outer null means "not in a couple",
     * ['partner' => null] means "in a couple, partner unknown".
     *
     * @return ?array{partner: ?array{name: string, bjUserId: int}}
     */
    public function forMember(array $bjUser): ?array
    {
        $partners = $this->forMembers([$bjUser]);
        $id = (int) ($bjUser['user_id'] ?? 0);
        return array_key_exists($id, $partners) ? ['partner' => $partners[$id]] : null;
    }

    /**
     * The member a change request names as partner. The request stores only the
     * email the member typed; this is the same match RenewalController makes
     * when the renewal is paid (primary or secondary address), so a null here
     * means that renewal would find no partner either.
     *
     * @return ?array{name: string, bjUserId: int}
     */
    public function memberByEmail(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        try {
            $data = $this->bj->get('users', ['search' => $email, 'limit' => 50]);
        } catch (BalleJauneException) {
            return null;
        }
        foreach ($data['users'] ?? [] as $u) {
            if (mb_strtolower((string) ($u['email'] ?? '')) === $email || mb_strtolower((string) ($u['email2'] ?? '')) === $email) {
                return ['name' => self::displayName($u), 'bjUserId' => (int) $u['user_id']];
            }
        }
        return null;
    }

    /**
     * Display names for arbitrary BJ ids — one batched call for the ones the
     * caller doesn't already hold.
     *
     * @param int[]              $ids
     * @param array<int, string> $knownNames
     * @return array<int, string>
     */
    public function names(array $ids, array $knownNames = []): array
    {
        $names = $knownNames;
        $wanted = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0 && !isset($names[$id]),
        )));
        if ($wanted === []) {
            return $names;
        }
        try {
            $data = $this->bj->get('users', ['user_id' => $wanted, 'limit' => 500]);
            foreach ($data['users'] ?? [] as $u) {
                $names[(int) $u['user_id']] = self::displayName($u);
            }
        } catch (BalleJauneException) {
            // Names fall back to "adhérent #id" in person(); the link still works.
        }
        return $names;
    }

    /** "NOM Prénom", the order the admin uses everywhere else. */
    public static function displayName(array $person): string
    {
        return trim(((string) ($person['lastname'] ?? '')) . ' ' . ((string) ($person['firstname'] ?? '')));
    }

    /** @return array{name: string, bjUserId: int} */
    private function person(int $bjUserId, array $names): array
    {
        $name = (string) ($names[$bjUserId] ?? '');
        return ['name' => $name !== '' ? $name : 'adhérent #' . $bjUserId, 'bjUserId' => $bjUserId];
    }

    /**
     * Couple applications among $ids, as payer/partner pairs. The applicant
     * (position 1) is always the one who pays; the partner (position 2) is
     * null only if the applicant ticked "couple" and never filled that step.
     *
     * @param int[] $ids
     * @return array<int, array{payer: array{name: string, bjUserId: int}, partner: ?array{name: string, bjUserId: int}}>
     */
    private function coupleApplications(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT ap.application_id, ap.position, ap.firstname, ap.lastname, ap.bj_user_id
               FROM applications a
               JOIN application_people ap ON ap.application_id = a.id
              WHERE a.is_couple = 1 AND a.id IN ({$in})"
        );
        $stmt->execute($ids);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $applicationId = (int) $row['application_id'];
            $result[$applicationId] ??= ['payer' => ['name' => '', 'bjUserId' => 0], 'partner' => null];
            $person = ['name' => self::displayName($row), 'bjUserId' => (int) $row['bj_user_id']];
            if ((int) $row['position'] === 1) {
                $result[$applicationId]['payer'] = $person;
            } else {
                $result[$applicationId]['partner'] = $person;
            }
        }
        return $result;
    }

    /**
     * The member_formulas row each of these orders fulfilled for someone other
     * than its payer — a couple renewal writes one row per person, both
     * pointing at the same order.
     *
     * @param int[] $orderIds
     * @return array<int, int> order id => the non-payer's bj_user_id
     */
    private function partnersFromFormulas(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT mf.order_id, mf.bj_user_id
               FROM member_formulas mf
               JOIN orders o ON o.id = mf.order_id
              WHERE mf.bj_user_id != o.bj_user_id AND mf.order_id IN ({$in})"
        );
        $stmt->execute($orderIds);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['order_id']] = (int) $row['bj_user_id'];
        }
        return $result;
    }

    /**
     * Each member's most recent season on file, shaped like
     * RenewalService::knownFormula() — batched, since MySQL 5.7 has no window
     * functions to pick "latest per user" in one pass.
     *
     * @param int[] $bjUserIds
     * @return array<int, array>
     */
    private function latestFormulas(array $bjUserIds): array
    {
        if ($bjUserIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($bjUserIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT mf.bj_user_id, mf.subscription_type, mf.is_couple, mf.competitor, mf.lessons, mf.partner_bj_user_id
               FROM member_formulas mf
               JOIN (SELECT bj_user_id, MAX(season_start_year) AS latest
                       FROM member_formulas
                      WHERE bj_user_id IN ({$in})
                      GROUP BY bj_user_id) last
                 ON last.bj_user_id = mf.bj_user_id AND last.latest = mf.season_start_year"
        );
        $stmt->execute($bjUserIds);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['bj_user_id']] = $row;
        }
        return $result;
    }
}
