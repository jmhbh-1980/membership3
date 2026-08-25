<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\RoleResolver;
use App\Service\BalleJaune\SubscriptionResolver;
use App\Service\OrderBreakdownService;
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

    private const array ARCHIVED_STATUSES = ['canceled', 'refunded', 'processed'];

    public function ordersHistory(Request $request, Response $response): Response
    {
        $stmt = $this->db->pdo()->query(
            "SELECT * FROM orders WHERE status NOT IN ('canceled', 'refunded', 'processed') ORDER BY created_at DESC LIMIT 200"
        );
        $archivedCount = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM orders WHERE status IN ('canceled', 'refunded', 'processed')"
        )->fetchColumn();

        return $this->renderer->render($response, 'pages/admin_orders.php', [
            'title'         => 'Commandes',
            'orders'        => $stmt->fetchAll(),
            'archived'      => false,
            'archivedCount' => $archivedCount,
            'csrf'          => Csrf::token(),
        ]);
    }

    public function archivedOrders(Request $request, Response $response): Response
    {
        $stmt = $this->db->pdo()->query(
            "SELECT * FROM orders WHERE status IN ('canceled', 'refunded', 'processed') ORDER BY updated_at DESC LIMIT 200"
        );

        return $this->renderer->render($response, 'pages/admin_orders.php', [
            'title'    => 'Commandes archivées',
            'orders'   => $stmt->fetchAll(),
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

        return $this->renderer->render($response, 'pages/admin_order_detail.php', [
            'title'     => 'Commande #' . $order['id'],
            'order'     => $order,
            'breakdown' => $this->breakdown->forOrder($order),
            'csrf'      => Csrf::token(),
        ]);
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
