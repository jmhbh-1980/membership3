<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ApplicationRepository;
use App\Repository\SettingsRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\RoleResolver;
use App\Service\RenewalService;
use App\Support\Csrf;
use App\Support\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

final class AdminController
{
    public function __construct(
        private readonly PhpRenderer $renderer,
        private readonly ApplicationRepository $applications,
        private readonly RenewalService $renewals,
        private readonly BalleJauneClient $bj,
        private readonly RoleResolver $roles,
        private readonly Db $db,
        private readonly SettingsRepository $settings,
    ) {
    }

    /** Admin shell — sections land here in later legs. */
    public function dashboard(Request $request, Response $response): Response
    {
        return $this->renderer->render($response, 'pages/admin_home.php', [
            'title'                => 'Administration',
            'user'                 => $request->getAttribute('user'),
            'counts'               => $this->counts(),
            'bugReportModeEnabled' => $this->settings->isEnabled('bug_report_mode'),
            'csrf'                 => Csrf::token(),
        ]);
    }

    public function enableBugReportMode(Request $request, Response $response): Response
    {
        return $this->toggleBugReportMode($request, $response, '1', 'bug_report_mode.enable');
    }

    public function disableBugReportMode(Request $request, Response $response): Response
    {
        return $this->toggleBugReportMode($request, $response, '0', 'bug_report_mode.disable');
    }

    private function toggleBugReportMode(Request $request, Response $response, string $value, string $action): Response
    {
        $body = (array) $request->getParsedBody();
        if (Csrf::validate($body['csrf'] ?? null)) {
            $this->settings->set('bug_report_mode', $value);
            $admin = $request->getAttribute('user');
            $this->audit($admin['email'], $action, '');
        }

        return $response->withStatus(302)->withHeader('Location', '/admin');
    }

    private function audit(string $actor, string $action, string $entityId, array $details = []): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO audit_log (actor, action, entity, entity_id, details, created_at)
             VALUES (?, ?, "settings", ?, ?, NOW())'
        );
        $stmt->execute([$actor, $action, $entityId, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }

    /** Cheap per-section counts shown as badges next to each dashboard link. */
    private function counts(): array
    {
        $visitorAclId = $this->roles->idForName('Visiteur');

        return [
            'demandes'    => count($this->applications->byStatus('submitted')),
            'abandonnees' => count($this->applications->abandonedDrafts()),
            'changements' => count($this->renewals->changeRequestsByStatus('pending')),
            'licences'    => (int) ($this->bj->get('users', [
                'filters' => json_encode(['keywords' => ['flag']]),
                'limit'   => 1,
            ])['total'] ?? 0),
            'semelles'    => (int) ($this->bj->get('users', [
                'filters' => json_encode(['roles' => [$visitorAclId], 'keywords' => ['subscription-paid']]),
                'limit'   => 1,
            ])['total'] ?? 0),
            'commandes'   => (int) $this->db->pdo()->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
            'cours'       => (int) $this->db->pdo()->query('SELECT COUNT(*) FROM lesson_enrollments')->fetchColumn(),
        ];
    }
}
