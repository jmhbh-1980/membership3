<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\RoleResolver;
use App\Service\BalleJaune\SubscriptionResolver;
use App\Service\RenewalService;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/**
 * Admin operational boards: members directory (live BJ search), group-lesson
 * subscribers, licence-flag board (flag=1 = licence not yet registered with
 * the federation), shoes-check board (Visiteur → Membre promotion), and
 * orders history.
 */
final class AdminOpsController
{
    public function __construct(
        private readonly BalleJauneClient $bj,
        private readonly SubscriptionResolver $subscriptions,
        private readonly RoleResolver $roles,
        private readonly PhpRenderer $renderer,
        private readonly Db $db,
        private readonly Logger $logger,
        private readonly RenewalService $renewals,
    ) {
    }

    // ── Members directory ────────────────────────────────────────────────

    public function members(Request $request, Response $response): Response
    {
        $search = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $users = [];
        if ($search !== '') {
            $data = $this->bj->get('users', ['search' => mb_substr($search, 0, 100), 'limit' => 50]);
            $users = $data['users'] ?? [];
        }
        $namesById = array_flip($this->subscriptions->map());

        $validSeasons = [];
        foreach ($users as $u) {
            $validSeasons[(int) $u['user_id']] = $this->renewals->validSeasonLabels(
                (int) $u['user_id'],
                (string) ($u['subscription_date_end'] ?? ''),
            );
        }

        return $this->renderer->render($response, 'pages/admin_members.php', [
            'title'        => 'Adhérents',
            'search'       => $search,
            'users'        => $users,
            'namesById'    => $namesById,
            'validSeasons' => $validSeasons,
        ]);
    }

    // ── Group lessons ────────────────────────────────────────────────────

    public function lessons(Request $request, Response $response): Response
    {
        $stmt = $this->db->pdo()->query(
            'SELECT * FROM lesson_enrollments ORDER BY season_start_year DESC, lastname, firstname'
        );
        $bySeason = [];
        foreach ($stmt->fetchAll() as $row) {
            $bySeason[(int) $row['season_start_year']][] = $row;
        }

        return $this->renderer->render($response, 'pages/admin_lessons.php', [
            'title'    => 'Cours collectifs',
            'bySeason' => $bySeason,
        ]);
    }

    // ── Licence flags ────────────────────────────────────────────────────

    public function licences(Request $request, Response $response): Response
    {
        $data = $this->bj->get('users', [
            'filters' => json_encode(['keywords' => ['flag']]),
            'limit'   => 200,
        ]);

        return $this->renderer->render($response, 'pages/admin_licences.php', [
            'title' => 'Licences à enregistrer',
            'csrf'  => Csrf::token(),
            'users' => $data['users'] ?? [],
        ]);
    }

    public function registerLicence(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/licences');
        }
        $licenceNumber = trim((string) ($body['license_number'] ?? ''));
        if ($licenceNumber === '' || mb_strlen($licenceNumber) > 15) {
            return $response->withStatus(302)->withHeader('Location', '/admin/licences');
        }

        $bjUserId = (int) $args['id'];
        $admin = $request->getAttribute('user');
        $this->bj->patch('users/' . $bjUserId, [
            'license_number'            => $licenceNumber,
            'license_practice'          => 'squash',
            'license_year'              => date('n') >= 9 ? (string) ((int) date('Y') + 1) : date('Y'),
            'license_registration_date' => date('Y-m-d'),
            'flag'                      => false,
        ]);
        $this->audit($admin['email'], 'licence.register', $bjUserId, ['license_number' => $licenceNumber]);

        return $response->withStatus(302)->withHeader('Location', '/admin/licences');
    }

    // ── Shoes check ──────────────────────────────────────────────────────

    public function shoes(Request $request, Response $response): Response
    {
        $visitorAclId = $this->roles->idForName('Visiteur');
        $data = $this->bj->get('users', [
            'filters' => json_encode(['roles' => [$visitorAclId], 'keywords' => ['subscription-paid']]),
            'limit'   => 200,
        ]);

        return $this->renderer->render($response, 'pages/admin_shoes.php', [
            'title' => 'Contrôle des semelles',
            'csrf'  => Csrf::token(),
            'users' => $data['users'] ?? [],
        ]);
    }

    public function approveShoes(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/semelles');
        }

        $bjUserId = (int) $args['id'];
        $user = $this->bj->get('users/' . $bjUserId)['user'];
        // Promote only when payment is confirmed — the board already filters
        // on paid members, this guards direct POSTs.
        if ((int) ($user['subscription_paid'] ?? 0) !== 1) {
            return $response->withStatus(302)->withHeader('Location', '/admin/semelles');
        }

        $admin = $request->getAttribute('user');
        $this->bj->patch('users/' . $bjUserId, ['acl_id' => $this->roles->idForName('Membre')]);
        $this->audit($admin['email'], 'shoes.approve', $bjUserId);
        $this->logger->info('admin', 'Member activated after shoes check', ['bj_user_id' => $bjUserId]);

        return $response->withStatus(302)->withHeader('Location', '/admin/semelles');
    }

    // ── Orders history ───────────────────────────────────────────────────

    public function ordersHistory(Request $request, Response $response): Response
    {
        $stmt = $this->db->pdo()->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 200');

        return $this->renderer->render($response, 'pages/admin_orders.php', [
            'title'  => 'Commandes',
            'orders' => $stmt->fetchAll(),
        ]);
    }

    private function audit(string $actor, string $action, int $bjUserId, array $details = []): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO audit_log (actor, action, entity, entity_id, details, created_at)
             VALUES (?, ?, "bj_user", ?, ?, NOW())'
        );
        $stmt->execute([$actor, $action, (string) $bjUserId, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }
}
