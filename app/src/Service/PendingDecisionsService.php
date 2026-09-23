<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ApplicationRepository;
use App\Repository\OrderRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\BalleJauneException;
use App\Support\Db;
use DateTimeImmutable;

/**
 * Everything currently waiting on an admin's yes/no, as one flat list.
 *
 * The club's five request-driven approvals each live on their own page, which
 * is right for making the decision — an application needs its documents, a
 * student discount needs its certificate, a transfer needs the bank statement.
 * What no single page can answer is "who has been waiting longest across all of
 * them", so that is exactly what this returns: one queue, oldest first,
 * regardless of type.
 *
 * Deliberately excluded: the shoes board (needs the member physically present,
 * so it can't be actioned from a desk) and the licence-flag board (a bulk
 * administrative chore, hundreds of rows, and nobody is blocked while it
 * waits). Both keep their own boards. The residence exception has nothing
 * pending by construction — an admin initiates it, no one requests it.
 *
 * Rows carry a URL to the page that owns the decision rather than duplicating
 * five decision handlers here; this aggregates, it never decides.
 */
final class PendingDecisionsService
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly OrderRepository $orders,
        private readonly RenewalService $renewals,
        private readonly BalleJauneClient $bj,
        private readonly Db $db,
        private readonly CoupleLinks $couples,
    ) {
    }

    /**
     * 'couple' is null for an individual request; for a couple it holds the
     * partner (null inside when the request names nobody), so the board shows
     * both people a decision affects.
     *
     * @return list<array{type:string, typeLabel:string, who:string, whoBjUserId:int, what:string,
     *                    since:string, days:int, url:string, blocking:bool,
     *                    couple:?array{partner:?array{name:string, bjUserId:int}}}>
     */
    public function all(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $rows = [
            ...$this->applicationRows(),
            ...$this->changeRequestRows(),
            ...$this->orderRows(),
        ];

        foreach ($rows as &$row) {
            $row['days'] = (int) $now->diff(new DateTimeImmutable($row['since']))->days;
        }
        unset($row);

        // The whole point of the board: one queue across every type, so the
        // person left hanging longest is at the top whatever they asked for.
        usort($rows, static fn (array $a, array $b): int => strcmp($a['since'], $b['since']));

        return $rows;
    }

    /** @return array<string, int> type => count, for the per-type summary */
    public function countsByType(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['type']] = ($counts[$row['type']] ?? 0) + 1;
        }
        return $counts;
    }

    private function applicationRows(): array
    {
        $rows = [];
        foreach ($this->applications->byStatus('submitted') as $app) {
            $people = $this->applications->people((int) $app['id']);
            $applicant = $people[1] ?? null;
            $partner = $people[2] ?? null;
            $rows[] = [
                'type'      => 'application',
                'typeLabel' => 'Demande d\'adhésion',
                'who'       => $applicant !== null
                    ? trim($applicant['lastname'] . ' ' . $applicant['firstname'])
                    : (string) $app['email'],
                // Not a member yet — nothing to link to until the application is fulfilled.
                'whoBjUserId' => 0,
                'what'      => $app['subscription_type'] !== ''
                    ? $app['subscription_type'] . ($app['is_couple'] ? ' (couple)' : '')
                    : 'formule non choisie',
                'since'     => (string) ($app['submitted_at'] ?: $app['created_at']),
                'days'      => 0,
                'url'       => '/admin/demandes/' . (int) $app['id'],
                'blocking'  => true,
                'couple'    => !$app['is_couple'] ? null : [
                    'partner' => $partner !== null ? ['name' => CoupleLinks::displayName($partner), 'bjUserId' => 0] : null,
                ],
            ];
        }
        return $rows;
    }

    private function changeRequestRows(): array
    {
        $requests = $this->renewals->changeRequestsByStatus('pending');

        // The partner this renewal will pair with: the email typed into the
        // request when the member named one, otherwise the partner already on
        // file — the same order RenewalController resolves them in.
        $onFile = [];
        foreach ($requests as $req) {
            if ($req['is_couple'] && trim((string) $req['partner_email']) === '') {
                $onFile[(int) $req['id']] = (int) ($this->renewals->knownFormula((int) $req['bj_user_id'])['partner_bj_user_id'] ?? 0);
            }
        }
        $names = $this->couples->names(array_values($onFile));

        $rows = [];
        foreach ($requests as $req) {
            $partnerEmail = trim((string) $req['partner_email']);
            $partnerId = $onFile[(int) $req['id']] ?? 0;
            $rows[] = [
                'type'      => 'change_request',
                'typeLabel' => $req['kind'] === 'licence' ? 'Demande de licence' : 'Changement de formule',
                'who'       => (string) $req['member_name'],
                'whoBjUserId' => (int) $req['bj_user_id'],
                'what'      => $req['kind'] === 'licence'
                    ? 'retrait/choix de licence'
                    : trim(($req['current_label'] !== '' ? $req['current_label'] . ' → ' : '') . $req['subscription_type'])
                        . ($req['is_couple'] ? ' (couple)' : ''),
                'since'     => (string) $req['created_at'],
                'days'      => 0,
                'url'       => '/admin/changements',
                'blocking'  => true,
                'couple'    => !$req['is_couple'] ? null : ['partner' => match (true) {
                    $partnerEmail !== '' => ['name' => $partnerEmail, 'bjUserId' => 0],
                    $partnerId > 0       => ['name' => $names[$partnerId] ?? 'adhérent #' . $partnerId, 'bjUserId' => $partnerId],
                    default              => null,
                }],
            ];
        }
        return $rows;
    }

    /**
     * The three order-status holds. All the same shape underneath: the order
     * already carries the discount and parks in an awaiting_* state before any
     * checkout exists, so approving is what releases it to 'pending' and lets
     * the member actually pay.
     */
    private function orderRows(): array
    {
        $sources = [
            ['orders' => $this->orders->awaitingPromoApproval(),   'type' => 'promo',
             'label' => 'Code promo',        'url' => '/admin/codes-promo/approbations'],
            ['orders' => $this->orders->awaitingStudentApproval(), 'type' => 'student',
             'label' => 'Réduction étudiant', 'url' => '/admin/reduction-etudiant'],
            ['orders' => $this->orders->awaitingBankTransfer(),    'type' => 'bank_transfer',
             'label' => 'Virement',           'url' => '/admin/virements'],
        ];

        $all = array_merge(...array_column($sources, 'orders'));
        $names = $this->namesForOrders($all);
        $knownNames = [];
        foreach ($names as $name) {
            if ($name['bjUserId'] > 0) {
                $knownNames[$name['bjUserId']] = $name['name'];
            }
        }
        $couples = $this->couples->forOrders($all, $knownNames);

        $rows = [];
        foreach ($sources as $source) {
            foreach ($source['orders'] as $order) {
                $amount = number_format((float) $order['amount'], 2, ',', ' ') . ' €';
                $rows[] = [
                    'type'      => $source['type'],
                    'typeLabel' => $source['label'],
                    'who'       => $names[(int) $order['id']]['name'] ?? '',
                    // A join order at this stage has no BJ account yet (still awaiting
                    // approval before it can even be paid) — only a renewal/credits/
                    // lessons order names an existing member worth linking.
                    'whoBjUserId' => $names[(int) $order['id']]['bjUserId'] ?? 0,
                    'what'      => $source['type'] === 'bank_transfer'
                        ? $amount . ' — réf. ' . OrderRepository::bankTransferReference($order)
                        : $amount . ' (commande #' . (int) $order['id'] . ')',
                    'since'     => (string) $order['created_at'],
                    'days'      => 0,
                    'url'       => $source['url'],
                    'blocking'  => true,
                    'couple'    => isset($couples[(int) $order['id']])
                        ? ['partner' => $couples[(int) $order['id']]['partner']]
                        : null,
                ];
            }
        }
        return $rows;
    }

    /**
     * Names for a mixed set of orders, batched: join orders resolve locally
     * through application_people, renewal/credits orders need Balle Jaune, and
     * those go in one call rather than one per row. A BJ outage leaves names
     * blank rather than failing the whole board — an admin can still see that
     * three decisions are waiting and open each one.
     *
     * @return array<int, array{name: string, bjUserId: int}> order id => name + linkable member id
     */
    private function namesForOrders(array $orders): array
    {
        if ($orders === []) {
            return [];
        }

        $applicationIds = [];
        $bjUserIds = [];
        foreach ($orders as $order) {
            if ($order['application_id'] !== null) {
                $applicationIds[(int) $order['application_id']] = true;
            } elseif ((int) $order['bj_user_id'] > 0) {
                $bjUserIds[(int) $order['bj_user_id']] = true;
            }
        }

        $byApplication = [];
        if ($applicationIds !== []) {
            $in = implode(',', array_fill(0, count($applicationIds), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT application_id, firstname, lastname FROM application_people
                  WHERE position = 1 AND application_id IN ({$in})"
            );
            $stmt->execute(array_keys($applicationIds));
            foreach ($stmt->fetchAll() as $person) {
                $byApplication[(int) $person['application_id']] = trim($person['lastname'] . ' ' . $person['firstname']);
            }
        }

        $byBjUser = [];
        if ($bjUserIds !== []) {
            try {
                $data = $this->bj->get('users', ['user_id' => array_keys($bjUserIds), 'limit' => 500]);
                foreach ($data['users'] ?? [] as $user) {
                    $byBjUser[(int) $user['user_id']] = trim(($user['lastname'] ?? '') . ' ' . ($user['firstname'] ?? ''));
                }
            } catch (BalleJauneException) {
                // Names stay blank; the board is still worth showing.
            }
        }

        $names = [];
        foreach ($orders as $order) {
            $names[(int) $order['id']] = $order['application_id'] !== null
                // Still awaiting approval before a checkout even exists — this applicant
                // has no BJ account yet, so there's nothing to link the name to.
                ? ['name' => $byApplication[(int) $order['application_id']] ?? '', 'bjUserId' => 0]
                : ['name' => $byBjUser[(int) $order['bj_user_id']] ?? '', 'bjUserId' => (int) $order['bj_user_id']];
        }
        return $names;
    }
}
