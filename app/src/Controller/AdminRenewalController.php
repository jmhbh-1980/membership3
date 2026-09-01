<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\BalleJauneException;
use App\Service\BalleJaune\SubscriptionResolver;
use App\Service\Mailer;
use App\Service\PricingService;
use App\Service\RenewalService;
use App\Service\Season;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Logger;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/**
 * Admin renewal tooling: formula-change request approvals and the renewal
 * campaign (filtered recipient list, select-all/cherry-pick, bulk send).
 */
final class AdminRenewalController
{
    /** BJ subscription types that are never real yearly members. */
    private const array EXCLUDED_SUBSCRIPTIONS = [
        'Membre du bureau', 'Planning', 'Professeur', 'Compte visiteur', 'Nouvel adhérent',
        'FORMULE TICKETS-5', 'FORMULE TICKETS-10',
    ];

    public function __construct(
        private readonly BalleJauneClient $bj,
        private readonly SubscriptionResolver $subscriptions,
        private readonly PricingService $pricing,
        private readonly RenewalService $renewals,
        private readonly Mailer $mailer,
        private readonly PhpRenderer $renderer,
        private readonly Db $db,
        private readonly Logger $logger,
    ) {
    }

    // ── Change requests ──────────────────────────────────────────────────

    public function changeRequests(Request $request, Response $response): Response
    {
        $pending = $this->renewals->changeRequestsByStatus('pending');
        $approved = $this->renewals->changeRequestsByStatus('approved');
        [$requests, $subscriptions, $liveLabel] = $this->enrichChangeRequests([...$pending, ...$approved]);

        // Pending first (needs a decision) — approved second (already
        // decided, just a safety valve to revoke a stale one) — Garennois
        // first within each group. usort is stable since PHP 8.0.
        usort($requests, fn (array $a, array $b): int =>
            [$a['status'] !== 'pending', $a['residence'] !== 'garennois']
            <=> [$b['status'] !== 'pending', $b['residence'] !== 'garennois']);

        $archivedCount = count($this->renewals->changeRequestsByStatus('refused'))
            + count($this->renewals->changeRequestsByStatus('completed'));

        return $this->renderer->render($response, 'pages/admin_change_requests.php', [
            'title'         => 'Changements d\'abonnement',
            'csrf'          => Csrf::token(),
            'requests'      => $requests,
            'subscriptions' => $subscriptions,
            'liveLabel'     => $liveLabel,
            'archived'      => false,
            'archivedCount' => $archivedCount,
        ]);
    }

    /** Refused/completed requests — read-only history, no decision left to make. */
    public function archivedChangeRequests(Request $request, Response $response): Response
    {
        $refused = $this->renewals->changeRequestsByStatus('refused');
        $completed = $this->renewals->changeRequestsByStatus('completed');
        [$requests, $subscriptions, $liveLabel] = $this->enrichChangeRequests([...$refused, ...$completed]);

        // Most recently decided first — a historical record, not a queue.
        usort($requests, fn (array $a, array $b): int => strcmp((string) $b['decided_at'], (string) $a['decided_at']));

        return $this->renderer->render($response, 'pages/admin_change_requests.php', [
            'title'         => 'Changements d\'abonnement — historique',
            'csrf'          => Csrf::token(),
            'requests'      => $requests,
            'subscriptions' => $subscriptions,
            'liveLabel'     => $liveLabel,
            'archived'      => true,
            'archivedCount' => null,
        ]);
    }

    /**
     * Shared enrichment for both the live and archived change-request views:
     * per-season subscription-label lookup (requests can span seasons — one
     * created just before a rollover, still unresolved after it) and each
     * request's live BJ subscription label + residence ("Abonnement actuel" is
     * re-fetched rather than trusting current_label, a snapshot from when the
     * member submitted the request that can go stale if BJ is hand-edited
     * before the admin decides — falls back to the stored snapshot if BJ is
     * unreachable).
     *
     * @return array{0: array[], 1: array, 2: array}
     */
    private function enrichChangeRequests(array $requests): array
    {
        $subscriptions = [];
        foreach (array_unique(array_column($requests, 'season_start_year')) as $startYear) {
            $season = new Season((int) $startYear);
            $subscriptions += $this->pricing->subscriptionsFor(PricingService::RESIDENCE_GARENNOIS, $season, midiResidencyOverride: true)
                + $this->pricing->subscriptionsFor(PricingService::RESIDENCE_HORS_COMMUNE, $season, midiResidencyOverride: true);
        }

        $liveLabel = [];
        foreach ($requests as &$req) {
            try {
                $bjUser = $this->bj->get('users/' . $req['bj_user_id'])['user'];
                $liveLabel[$req['id']] = array_search((int) $bjUser['subscription_id'], $this->subscriptions->map(), true) ?: null;
                $req['residence'] = $this->pricing->residenceForZip((string) ($bjUser['postalcode'] ?? ''));
            } catch (BalleJauneException) {
                $liveLabel[$req['id']] = null;
                $req['residence'] = '';
            }
        }
        unset($req);

        return [$requests, $subscriptions, $liveLabel];
    }

    public function decideChangeRequest(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $changeRequest = $this->renewals->findChangeRequest((int) $args['id']);
        $decision = (string) ($body['decision'] ?? '');
        $wasApproved = $changeRequest !== null && $changeRequest['status'] === 'approved';
        // A pending request can be approved or refused; an already-approved
        // one can only be walked back (refused) — re-approving an
        // already-approved row is a pointless no-op the UI never offers, so
        // a crafted POST for it is rejected here too, not just hidden.
        $validTransition = $changeRequest !== null
            && ($changeRequest['status'] === 'pending' || ($wasApproved && $decision === 'refuse'));
        if (!$validTransition || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/changements');
        }

        $admin = $request->getAttribute('user');
        $approved = $decision === 'approve';
        $note = trim((string) ($body['note'] ?? ''));
        $this->renewals->decideChangeRequest((int) $changeRequest['id'], $approved, $note);

        $isLicenceRequest = $changeRequest['kind'] === 'licence';
        $subject = $isLicenceRequest ? 'retrait de licence' : 'changement de formule';
        if ($wasApproved) {
            // Revoking a request the member was already told was accepted —
            // the plain "refused" copy below would read as factually wrong
            // ("could not be accepted") to someone already told it was.
            $this->mailer->send(
                $changeRequest['email'],
                ucfirst($subject) . ' annulé — Bad & Squash',
                '<p>Bonjour,</p><p>Votre demande de ' . $subject . ' avait été acceptée, mais elle a finalement dû être annulée'
                . ($note !== '' ? ' : ' . htmlspecialchars($note, ENT_QUOTES) : '') . '.</p>'
                . '<p>Vous pouvez renouveler votre formule actuelle depuis votre espace, ou contacter le club.</p>',
                'change_revoked',
            );
        } elseif ($approved) {
            $this->mailer->send(
                $changeRequest['email'],
                ucfirst($subject) . ' accepté — Bad & Squash',
                '<p>Bonjour,</p><p>Votre demande de ' . $subject . ' a été acceptée'
                . ($note !== '' ? ' : ' . htmlspecialchars($note, ENT_QUOTES) : '') . '.</p>'
                . '<p>Connectez-vous à votre espace pour procéder au paiement de votre renouvellement.</p>',
                'change_approved',
            );
        } else {
            $this->mailer->send(
                $changeRequest['email'],
                ucfirst($subject) . ' — Bad & Squash',
                '<p>Bonjour,</p><p>Votre demande de ' . $subject . ' n\'a pas pu être acceptée'
                . ($note !== '' ? ' : ' . htmlspecialchars($note, ENT_QUOTES) : '') . '.</p>'
                . '<p>Vous pouvez renouveler votre formule actuelle depuis votre espace, ou contacter le club.</p>',
                'change_refused',
            );
        }

        // 'from' matters here specifically because admin_note/decided_at on the
        // row itself get overwritten by every decision — audit_log is where the
        // history (e.g. "this was approved before being revoked") actually lives.
        $auditAction = match (true) {
            $wasApproved => 'change_request.revoke',
            $approved    => 'change_request.approve',
            default      => 'change_request.refuse',
        };
        $this->audit($admin['email'], $auditAction, (int) $changeRequest['id'], ['note' => $note, 'from' => $changeRequest['status']]);
        return $response->withStatus(302)->withHeader('Location', '/admin/changements');
    }

    // ── Renewal campaign ─────────────────────────────────────────────────

    public function campaign(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $filters = [
            'paid'    => $query['paye'] ?? 'all',      // 1 | 0 | all
            'active'  => $query['statut'] ?? 'active', // active | expired | all
        ];

        $members = array_filter(
            $this->yearlyMembers(),
            function (array $m) use ($filters): bool {
                if ($filters['paid'] !== 'all' && (string) $m['paid'] !== $filters['paid']) {
                    return false;
                }
                if ($filters['active'] === 'active' && $m['expired']) {
                    return false;
                }
                if ($filters['active'] === 'expired' && !$m['expired']) {
                    return false;
                }
                return true;
            }
        );

        return $this->renderer->render($response, 'pages/admin_campaign.php', [
            'title'   => 'Campagne de renouvellement',
            'csrf'    => Csrf::token(),
            'members' => $members,
            'filters' => $filters,
            'sent'    => isset($query['envoyes']) ? (int) $query['envoyes'] : null,
        ]);
    }

    public function campaignSend(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/campagne');
        }

        $admin = $request->getAttribute('user');
        $selectedIds = array_map('intval', (array) ($body['members'] ?? []));
        $byId = [];
        foreach ($this->yearlyMembers() as $member) {
            $byId[$member['user_id']] = $member;
        }

        $baseUrl = $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority();
        $sent = 0;
        foreach ($selectedIds as $id) {
            $member = $byId[$id] ?? null;
            if ($member === null || $member['email'] === '') {
                continue;
            }
            $ok = $this->mailer->send(
                $member['email'],
                'Renouvelez votre adhésion — Bad & Squash',
                '<p>Bonjour ' . htmlspecialchars($member['firstname'], ENT_QUOTES) . ',</p>'
                . '<p>La nouvelle saison approche ! Renouvelez votre adhésion en ligne en quelques minutes :</p>'
                . '<p><a href="' . htmlspecialchars($baseUrl . '/connexion', ENT_QUOTES) . '">Renouveler mon adhésion</a></p>'
                . '<p>Connectez-vous simplement avec votre adresse email — aucun mot de passe requis.</p>',
                'renewal_campaign',
            );
            if ($ok) {
                $sent++;
            }
            usleep(150_000); // stay well under the SMTP relay's rate limits
        }

        $this->audit($admin['email'], 'campaign.send', 0, ['recipients' => count($selectedIds), 'sent' => $sent]);
        $this->logger->info('campaign', 'Renewal campaign sent', ['sent' => $sent]);

        return $response->withStatus(302)->withHeader('Location', '/admin/campagne?envoyes=' . $sent);
    }

    /**
     * All yearly members from BJ who actually have something to renew right
     * now (staff/internal/ticket subscriptions excluded at the source, and
     * — via renewalTarget()'s 'open' state — also excluding anyone already
     * covered through next season, or whose current subscription hasn't
     * lapsed while next season's pricing isn't published yet: neither is a
     * legitimate campaign target).
     *
     * @return array[] {user_id, firstname, lastname, email, subscription, paid, expired, date_end}
     */
    private function yearlyMembers(): array
    {
        $namesById = array_flip($this->subscriptions->map());
        $now = new DateTimeImmutable();
        $members = [];
        $offset = 0;

        do {
            $data = $this->bj->get('users', ['limit' => 200, 'offset' => $offset]);
            $users = $data['users'] ?? [];
            foreach ($users as $user) {
                $subscriptionName = $namesById[(int) $user['subscription_id']] ?? '';
                if ($subscriptionName === '' || in_array($subscriptionName, self::EXCLUDED_SUBSCRIPTIONS, true)) {
                    continue;
                }
                $dateEnd = (string) ($user['subscription_date_end'] ?? '');
                if ($this->renewals->renewalTarget($now, $dateEnd)['state'] !== 'open') {
                    continue;
                }
                $members[] = [
                    'user_id'      => (int) $user['user_id'],
                    'firstname'    => (string) $user['firstname'],
                    'lastname'     => (string) $user['lastname'],
                    'email'        => (string) $user['email'],
                    'subscription' => $subscriptionName,
                    'paid'         => (int) ($user['subscription_paid'] ?? 0),
                    // Deliberately a plain "is BJ's raw date already before today"
                    // check, not RenewalService::subscriptionCovers() — every row here
                    // already failed that season-marker check (it's how yearlyMembers()
                    // filtered them in), so reusing it would make this always true.
                    'expired'      => $dateEnd !== '' && $dateEnd !== '0000-00-00' && $dateEnd < $now->format('Y-m-d'),
                    'date_end'     => $dateEnd,
                    'residence'    => $this->pricing->residenceForZip((string) ($user['postalcode'] ?? '')),
                ];
            }
            $offset += 200;
            $total = (int) ($data['total'] ?? 0);
        } while ($offset < $total && $users !== []);

        // Garennois first (the campaign's own priority queue), then alphabetical within each group.
        usort($members, fn ($a, $b) => [$a['residence'] !== 'garennois', $a['lastname'], $a['firstname']]
            <=> [$b['residence'] !== 'garennois', $b['lastname'], $b['firstname']]);
        return $members;
    }

    private function audit(string $actor, string $action, int $entityId, array $details = []): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO audit_log (actor, action, entity, entity_id, details, created_at)
             VALUES (?, ?, "renewal", ?, ?, NOW())'
        );
        $stmt->execute([$actor, $action, (string) $entityId, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }
}
