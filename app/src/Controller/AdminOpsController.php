<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\BalleJauneException;
use App\Service\BalleJaune\RoleResolver;
use App\Service\BalleJaune\SubscriptionResolver;
use App\Service\OrderBreakdownService;
use App\Service\PricingService;
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
        private readonly OrderRepository $orders,
        private readonly OrderBreakdownService $breakdown,
        private readonly PricingService $pricing,
    ) {
    }

    /** Adds a 'residence' key ('garennois' | 'hors-commune') to each BJ user row, from its postalcode. */
    private function withResidence(array $users): array
    {
        foreach ($users as &$u) {
            $u['residence'] = $this->pricing->residenceForZip((string) ($u['postalcode'] ?? ''));
        }
        unset($u);
        return $users;
    }

    /** Stable: Garennois rows first, original relative order preserved within each group. */
    private static function sortGarennoisFirst(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => ($a['residence'] !== 'garennois') <=> ($b['residence'] !== 'garennois'));
        return $rows;
    }

    // ── Members directory ────────────────────────────────────────────────

    public function members(Request $request, Response $response): Response
    {
        $search = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $users = [];
        if ($search !== '') {
            $data = $this->bj->get('users', ['search' => mb_substr($search, 0, 100), 'limit' => 50]);
            $users = $this->withResidence($data['users'] ?? []);
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
        $rows = $stmt->fetchAll();

        // lesson_enrollments has no postalcode of its own — one batched BJ
        // call for every distinct member avoids an N+1 lookup per row.
        $residenceById = [];
        $bjUserIds = array_unique(array_column($rows, 'bj_user_id'));
        if ($bjUserIds !== []) {
            $data = $this->bj->get('users', ['user_id' => array_map('intval', $bjUserIds), 'limit' => 500]);
            foreach ($data['users'] ?? [] as $u) {
                $residenceById[(int) $u['user_id']] = $this->pricing->residenceForZip((string) ($u['postalcode'] ?? ''));
            }
        }

        $bySeason = [];
        foreach ($rows as $row) {
            $row['residence'] = $residenceById[(int) $row['bj_user_id']] ?? '';
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
            'users' => self::sortGarennoisFirst($this->withResidence($data['users'] ?? [])),
        ]);
    }

    /** Clears BJ's flag=1 ("licence not registered yet") — the licence itself is created/tracked in BJ directly, this board is only a to-do list for that. */
    public function clearLicenceFlag(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/licences');
        }

        $bjUserId = (int) $args['id'];
        $admin = $request->getAttribute('user');
        $this->bj->patch('users/' . $bjUserId, ['flag' => false]);
        $this->audit($admin['email'], 'licence.unflag', $bjUserId);

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
            'users' => self::sortGarennoisFirst($this->withResidence($data['users'] ?? [])),
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

    private const array ARCHIVED_STATUSES = ['canceled', 'refunded', 'processed'];

    public function ordersHistory(Request $request, Response $response): Response
    {
        $stmt = $this->db->pdo()->query(
            "SELECT o.*, a.residence AS app_residence FROM orders o
             LEFT JOIN applications a ON a.id = o.application_id
             WHERE o.status NOT IN ('canceled', 'refunded', 'processed') ORDER BY o.created_at DESC LIMIT 200"
        );
        $archivedCount = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM orders WHERE status IN ('canceled', 'refunded', 'processed')"
        )->fetchColumn();

        return $this->renderer->render($response, 'pages/admin_orders.php', [
            'title'         => 'Commandes',
            'orders'        => $this->withOrderResidence($stmt->fetchAll()),
            'archived'      => false,
            'archivedCount' => $archivedCount,
            'csrf'          => Csrf::token(),
        ]);
    }

    public function archivedOrders(Request $request, Response $response): Response
    {
        $stmt = $this->db->pdo()->query(
            "SELECT o.*, a.residence AS app_residence FROM orders o
             LEFT JOIN applications a ON a.id = o.application_id
             WHERE o.status IN ('canceled', 'refunded', 'processed') ORDER BY o.updated_at DESC LIMIT 200"
        );

        return $this->renderer->render($response, 'pages/admin_orders.php', [
            'title'    => 'Commandes archivées',
            'orders'   => $this->withOrderResidence($stmt->fetchAll()),
            'archived' => true,
            'csrf'     => Csrf::token(),
        ]);
    }

    public function orderDetail(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }
        $order['residence'] = $this->residenceForOrder($order);

        return $this->renderer->render($response, 'pages/admin_order_detail.php', [
            'title'     => 'Commande #' . $order['id'],
            'order'     => $order,
            'breakdown' => $this->breakdown->forOrder($order),
            'csrf'      => Csrf::token(),
        ]);
    }

    /**
     * Join orders carry residence via their application (LEFT JOINed as
     * app_residence); renewal/credits orders have no local residence source,
     * so their bj_user_id's are resolved in one batched BJ call rather than
     * one lookup per row.
     */
    private function withOrderResidence(array $orders): array
    {
        $bjUserIds = [];
        foreach ($orders as $o) {
            if ($o['application_id'] === null && (int) $o['bj_user_id'] > 0) {
                $bjUserIds[(int) $o['bj_user_id']] = true;
            }
        }
        $residenceByBjUser = [];
        if ($bjUserIds !== []) {
            $data = $this->bj->get('users', ['user_id' => array_keys($bjUserIds), 'limit' => 500]);
            foreach ($data['users'] ?? [] as $u) {
                $residenceByBjUser[(int) $u['user_id']] = $this->pricing->residenceForZip((string) ($u['postalcode'] ?? ''));
            }
        }
        foreach ($orders as &$o) {
            $o['residence'] = $o['application_id'] !== null
                ? (string) $o['app_residence']
                : ($residenceByBjUser[(int) $o['bj_user_id']] ?? '');
        }
        unset($o);
        return $orders;
    }

    private function residenceForOrder(array $order): string
    {
        if ($order['application_id'] !== null) {
            $stmt = $this->db->pdo()->prepare('SELECT residence FROM applications WHERE id = ?');
            $stmt->execute([$order['application_id']]);
            return (string) ($stmt->fetchColumn() ?: '');
        }
        if ((int) $order['bj_user_id'] > 0) {
            try {
                $bjUser = $this->bj->get('users/' . $order['bj_user_id'])['user'];
                return $this->pricing->residenceForZip((string) ($bjUser['postalcode'] ?? ''));
            } catch (BalleJauneException) {
                return '';
            }
        }
        return '';
    }

    public function cancelOrder(Request $request, Response $response, array $args): Response
    {
        return $this->setOrderStatus($request, $response, (int) $args['id'], 'canceled', 'order.cancel');
    }

    public function refundOrder(Request $request, Response $response, array $args): Response
    {
        return $this->setOrderStatus($request, $response, (int) $args['id'], 'refunded', 'order.refund', requireFulfilled: true);
    }

    public function processOrder(Request $request, Response $response, array $args): Response
    {
        return $this->setOrderStatus($request, $response, (int) $args['id'], 'processed', 'order.process', requireFulfilled: true);
    }

    /**
     * Admin-only disposition reflecting reality that BJ has no signal for at
     * all (cancellation/refund made directly in BJ, or admin follow-up being
     * done) — recorded by hand rather than derived. Not part of the payment/
     * fulfillment state machine transitions in PaymentController.
     *
     * One-way: canceled/refunded/processed orders are archived and locked —
     * rejected here regardless of target, so a crafted POST can't reopen one
     * even though the UI already hides the buttons. refunded/processed also
     * require the order to currently be fulfilled (Paid) — you can't refund
     * or finish processing something never paid.
     */
    private function setOrderStatus(Request $request, Response $response, int $orderId, string $status, string $auditAction, bool $requireFulfilled = false): Response
    {
        $body = (array) $request->getParsedBody();
        $order = $this->orders->findById($orderId);
        $archived = $order !== null && in_array($order['status'], self::ARCHIVED_STATUSES, true);
        if (!Csrf::validate($body['csrf'] ?? null) || $order === null || $archived
            || ($requireFulfilled && $order['status'] !== 'fulfilled')) {
            return $response->withStatus(302)->withHeader('Location', '/admin/commandes');
        }

        $admin = $request->getAttribute('user');
        $this->orders->update($orderId, ['status' => $status]);
        $this->audit($admin['email'], $auditAction, $orderId, ['from' => $order['status']], 'order');

        return $response->withStatus(302)->withHeader('Location', '/admin/commandes');
    }

    private function audit(string $actor, string $action, int $entityId, array $details = [], string $entity = 'bj_user'): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO audit_log (actor, action, entity, entity_id, details, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$actor, $action, $entity, (string) $entityId, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }
}
