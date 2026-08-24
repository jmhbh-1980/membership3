<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\SubscriptionResolver;
use App\Service\Mailer;
use App\Service\PricingService;
use App\Service\RenewalService;
use App\Service\Season;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Logger;
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

        // Pending requests can span different seasons (one created just
        // before a season rollover, still awaiting approval after it) — build
        // the subscription lookup for every season actually represented
        // rather than a single "currently offered" one, so no label lookup misses.
        $subscriptions = [];
        foreach (array_unique(array_column($pending, 'season_start_year')) as $startYear) {
            $season = new Season((int) $startYear);
            $subscriptions += $this->pricing->subscriptionsFor(PricingService::RESIDENCE_GARENNOIS, $season, midiResidencyOverride: true)
                + $this->pricing->subscriptionsFor(PricingService::RESIDENCE_HORS_COMMUNE, $season, midiResidencyOverride: true);
        }

        return $this->renderer->render($response, 'pages/admin_change_requests.php', [
            'title'         => 'Changements d\'abonnement',
            'csrf'          => Csrf::token(),
            'pending'       => $pending,
            'subscriptions' => $subscriptions,
        ]);
    }

    public function decideChangeRequest(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $changeRequest = $this->renewals->findChangeRequest((int) $args['id']);
        if ($changeRequest === null || $changeRequest['status'] !== 'pending' || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/changements');
        }

        $admin = $request->getAttribute('user');
        $approved = ($body['decision'] ?? '') === 'approve';
        $note = trim((string) ($body['note'] ?? ''));
        $this->renewals->decideChangeRequest((int) $changeRequest['id'], $approved, $note);

        $isLicenceRequest = $changeRequest['kind'] === 'licence';
        $subject = $isLicenceRequest ? 'retrait de licence' : 'changement de formule';
        if ($approved) {
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

        $this->audit($admin['email'], $approved ? 'change_request.approve' : 'change_request.refuse', (int) $changeRequest['id'], ['note' => $note]);
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
     * All yearly members from BJ (staff/internal/ticket subscriptions
     * excluded at the source), enriched with paid/expired flags.
     *
     * @return array[] {user_id, firstname, lastname, email, subscription, paid, expired, date_end}
     */
    private function yearlyMembers(): array
    {
        $namesById = array_flip($this->subscriptions->map());
        $today = date('Y-m-d');
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
                $members[] = [
                    'user_id'      => (int) $user['user_id'],
                    'firstname'    => (string) $user['firstname'],
                    'lastname'     => (string) $user['lastname'],
                    'email'        => (string) $user['email'],
                    'subscription' => $subscriptionName,
                    'paid'         => (int) ($user['subscription_paid'] ?? 0),
                    'expired'      => $dateEnd === '' || $dateEnd === '0000-00-00' || $dateEnd < $today,
                    'date_end'     => $dateEnd,
                ];
            }
            $offset += 200;
            $total = (int) ($data['total'] ?? 0);
        } while ($offset < $total && $users !== []);

        usort($members, fn ($a, $b) => [$a['lastname'], $a['firstname']] <=> [$b['lastname'], $b['firstname']]);
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
