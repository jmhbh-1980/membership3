<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\FederationLicenceExport;
use App\Service\MemberDossierService;
use App\Service\Season;
use App\Support\Csrf;
use DateTimeImmutable;
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
    /** Why the federation file came back empty, as AdminOpsController::exportMembers() names it (FederationLicenceExport::blocker()). */
    private const array EXPORT_BLOCKERS = ['saison', 'licence', 'infos'];

    public function __construct(
        private readonly MemberDossierService $dossiers,
        private readonly PhpRenderer $renderer,
        private readonly FederationLicenceExport $federationExport,
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $dossier = $this->dossiers->forBjUser((int) $args['id']);
        if ($dossier === null) {
            return $response->withStatus(302)->withHeader('Location', '/admin/membres');
        }

        $bjUser = $dossier['bjUser'];
        $query = $request->getQueryParams();
        $exportBlocker = in_array($query['export-vide'] ?? null, self::EXPORT_BLOCKERS, true) ? (string) $query['export-vide'] : null;

        return $this->renderer->render($response, 'pages/admin_member_dossier.php', [
            'title'   => trim(($bjUser['lastname'] ?? '') . ' ' . ($bjUser['firstname'] ?? '')) ?: 'Fiche adhérent',
            'csrf'    => Csrf::token(),
            'dossier' => $dossier,
            // Back from deleting a payment recorded outside the app (AdminManualOrderController::delete()).
            'deletedOrder' => (int) ($query['commande-supprimee'] ?? 0),
            // Back from asking the federation file for this one member (AdminOpsController::exportMembers()) with nothing to send.
            'exportBlocker'     => $exportBlocker,
            'federationMissing' => $exportBlocker === 'infos' ? $this->federationExport->missingFields($bjUser) : [],
            'season'            => Season::fromDate(new DateTimeImmutable()),
        ]);
    }
}
