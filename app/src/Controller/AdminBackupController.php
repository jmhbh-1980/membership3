<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BackupService;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use Slim\Views\PhpRenderer;
use Throwable;

/**
 * Admin-triggered full backups (DB dump + uploads/ + pricing_data/) — see
 * DEPLOY.md's Sauvegardes section for why this exists and what it does and
 * doesn't cover.
 */
final class AdminBackupController
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly PhpRenderer $renderer,
        private readonly Db $db,
        private readonly Logger $logger,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->renderer->render($response, 'pages/admin_backups.php', [
            'title'   => 'Sauvegardes',
            'csrf'    => Csrf::token(),
            'backups' => $this->backups->list(),
        ]);
    }

    public function generate(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/sauvegardes');
        }

        $admin = $request->getAttribute('user');

        try {
            $result = $this->backups->generate();
            $this->audit((string) $admin['email'], 'backup.generate', $result['filename'], ['size' => $result['size']]);
        } catch (Throwable $e) {
            $this->logger->error('admin', 'Backup generation failed', ['error' => $e->getMessage()]);
        }

        return $response->withStatus(302)->withHeader('Location', '/admin/sauvegardes');
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        $path = $this->backups->pathFor((string) $args['file']);
        if ($path === null) {
            return $response->withStatus(404);
        }

        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($path) . '"')
            ->withBody(new Stream(fopen($path, 'rb')));
    }

    private function audit(string $actor, string $action, string $filename, array $details = []): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO audit_log (actor, action, entity, entity_id, details, created_at)
             VALUES (?, ?, "backup", ?, ?, NOW())',
        );
        $stmt->execute([$actor, $action, $filename, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }
}
