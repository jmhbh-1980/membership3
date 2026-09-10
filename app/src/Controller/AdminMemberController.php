<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MemberDossierService;
use App\Support\Csrf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/**
 * The member dossier: one admin page holding everything the club knows about a
 * member. Read-only by design — every action it surfaces (grant an exception,
 * view as, decide a change request, open an order) links out to the page that
 * already owns it, so there is exactly one implementation of each decision.
 *
 * Documents are streamed by the existing application-scoped route rather than a
 * new one: they belong to an application, the route is already admin-gated, and
 * it already validates the requested filename against that application's stored
 * names. Duplicating it here would mean duplicating that allowlist too.
 */
final class AdminMemberController
{
    public function __construct(
        private readonly MemberDossierService $dossiers,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $dossier = $this->dossiers->forBjUser((int) $args['id']);
        if ($dossier === null) {
            return $response->withStatus(302)->withHeader('Location', '/admin/membres');
        }

        $bjUser = $dossier['bjUser'];

        return $this->renderer->render($response, 'pages/admin_member_dossier.php', [
            'title'   => trim(($bjUser['lastname'] ?? '') . ' ' . ($bjUser['firstname'] ?? '')) ?: 'Fiche adhérent',
            'csrf'    => Csrf::token(),
            'dossier' => $dossier,
        ]);
    }
}
