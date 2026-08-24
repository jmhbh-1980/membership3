<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ApplicationRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\RoleResolver;
use App\Service\RenewalService;
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
    ) {
    }

    /** Admin shell — sections land here in later legs. */
    public function dashboard(Request $request, Response $response): Response
    {
        return $this->renderer->render($response, 'pages/admin_home.php', [
            'title'  => 'Administration',
            'user'   => $request->getAttribute('user'),
            'counts' => $this->counts(),
        ]);
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
