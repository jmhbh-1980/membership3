<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/** Read-only browser over the audit_log table, written to by every admin controller's audit() helper. */
final class AdminAuditController
{
    private const int PER_PAGE = 50;

    public function __construct(
        private readonly PhpRenderer $renderer,
        private readonly Db $db,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $filters = [
            'actor'  => trim((string) ($query['actor'] ?? '')),
            'action' => trim((string) ($query['action'] ?? '')),
            'entity' => trim((string) ($query['entity'] ?? '')),
            'from'   => trim((string) ($query['from'] ?? '')),
            'to'     => trim((string) ($query['to'] ?? '')),
        ];

        $where = [];
        $params = [];
        if ($filters['actor'] !== '') {
            $where[] = 'actor LIKE ?';
            $params[] = '%' . $filters['actor'] . '%';
        }
        if ($filters['action'] !== '') {
            $where[] = 'action = ?';
            $params[] = $filters['action'];
        }
        if ($filters['entity'] !== '') {
            $where[] = 'entity = ?';
            $params[] = $filters['entity'];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from']) === 1) {
            $where[] = 'created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to']) === 1) {
            $where[] = 'created_at <= ?';
            $params[] = $filters['to'] . ' 23:59:59';
        }
        $whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

        $countStmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM audit_log $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($query['page'] ?? 1)), $totalPages);
        $offset = ($page - 1) * self::PER_PAGE;

        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM audit_log $whereSql ORDER BY created_at DESC, id DESC LIMIT " . self::PER_PAGE . " OFFSET $offset"
        );
        $stmt->execute($params);

        return $this->renderer->render($response, 'pages/admin_audit.php', [
            'title'      => "Journal d'audit",
            'entries'    => $stmt->fetchAll(),
            'actions'    => $this->db->pdo()->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(\PDO::FETCH_COLUMN),
            'entities'   => $this->db->pdo()->query('SELECT DISTINCT entity FROM audit_log ORDER BY entity')->fetchAll(\PDO::FETCH_COLUMN),
            'filters'    => $filters,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);
    }
}
