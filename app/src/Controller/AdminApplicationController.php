<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ApplicationRepository;
use App\Service\Mailer;
use App\Service\PricingService;
use App\Service\Quote;
use App\Service\Season;
use App\Service\UploadService;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Logger;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use Slim\Views\PhpRenderer;

/**
 * Admin review of prospect applications: queue, detail with documents,
 * validate (→ payment email) or reject (→ reasoned email). Every decision
 * is written to audit_log.
 */
final class AdminApplicationController
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly PricingService $pricing,
        private readonly UploadService $uploads,
        private readonly Mailer $mailer,
        private readonly PhpRenderer $renderer,
        private readonly Db $db,
        private readonly Logger $logger,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $pending = $this->applications->byStatus('submitted');
        $rows = [];
        foreach ($pending as $app) {
            $people = $this->applications->people((int) $app['id']);
            $rows[] = ['app' => $app, 'people' => $people];
        }
        usort($rows, fn (array $a, array $b): int => ($a['app']['residence'] !== 'garennois') <=> ($b['app']['residence'] !== 'garennois'));

        return $this->renderer->render($response, 'pages/admin_applications.php', [
            'title' => 'Demandes d\'adhésion',
            'rows'  => $rows,
        ]);
    }

    /** Signups still stuck in draft — started but never submitted. */
    public function abandoned(Request $request, Response $response): Response
    {
        $drafts = $this->applications->abandonedDrafts();
        $rows = [];
        foreach ($drafts as $app) {
            $people = $this->applications->people((int) $app['id']);
            $rows[] = [
                'app'       => $app,
                'people'    => $people,
                'documents' => count($this->applications->documents((int) $app['id'])),
            ];
        }
        usort($rows, fn (array $a, array $b): int => ($a['app']['residence'] !== 'garennois') <=> ($b['app']['residence'] !== 'garennois'));

        return $this->renderer->render($response, 'pages/admin_applications_abandoned.php', [
            'title' => 'Demandes abandonnées',
            'csrf'  => Csrf::token(),
            'rows'  => $rows,
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $app = $this->applications->findById((int) $args['id']);
        if ($app === null) {
            return $response->withStatus(302)->withHeader('Location', '/admin/demandes');
        }

        return $this->renderer->render($response, 'pages/admin_application_detail.php', [
            'title'        => 'Demande #' . $app['id'],
            'csrf'         => Csrf::token(),
            'app'          => $app,
            'people'       => $this->applications->people((int) $app['id']),
            'documents'    => $this->applications->documents((int) $app['id']),
            'attestations' => $this->applications->attestations((int) $app['id']),
            'quote'        => $this->quoteFor($app),
        ]);
    }

    public function decide(Request $request, Response $response, array $args): Response
    {
        $app = $this->applications->findById((int) $args['id']);
        $body = (array) $request->getParsedBody();
        if ($app === null || !Csrf::validate($body['csrf'] ?? null) || $app['status'] !== 'submitted') {
            return $response->withStatus(302)->withHeader('Location', '/admin/demandes');
        }

        $admin = $request->getAttribute('user');
        $decision = (string) ($body['decision'] ?? '');

        if ($decision === 'validate') {
            $this->applications->setStatus((int) $app['id'], 'validated', ['validated_at' => date('Y-m-d H:i:s')]);
            $link = $this->baseUrl($request) . '/paiement/' . $app['token'];
            $this->mailer->send(
                $app['email'],
                'Demande d\'adhésion validée — passez au paiement',
                '<p>Bonjour,</p><p>Bonne nouvelle : votre demande d\'adhésion a été validée par le club.</p>'
                . '<p>Pour finaliser votre adhésion, procédez au paiement en ligne :</p>'
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Payer mon adhésion</a></p>',
                'application_validated',
            );
            $this->audit($admin['email'], 'application.validate', (int) $app['id']);
        } elseif ($decision === 'reject') {
            $reason = trim((string) ($body['reason'] ?? ''));
            if ($reason === '') {
                return $response->withStatus(302)->withHeader('Location', '/admin/demandes/' . $app['id']);
            }
            $this->applications->setStatus((int) $app['id'], 'rejected', ['rejection_reason' => mb_substr($reason, 0, 500)]);
            $this->mailer->send(
                $app['email'],
                'Votre demande d\'adhésion — Bad & Squash',
                '<p>Bonjour,</p><p>Après examen, le club ne peut pas donner suite à votre demande d\'adhésion.</p>'
                . '<p>Motif : ' . htmlspecialchars($reason, ENT_QUOTES) . '</p>'
                . '<p>Pour toute question, répondez à cet email ou contactez le club.</p>',
                'application_rejected',
            );
            $this->audit($admin['email'], 'application.reject', (int) $app['id'], ['reason' => $reason]);
        }

        return $response->withStatus(302)->withHeader('Location', '/admin/demandes');
    }

    /** Re-sends the resume-link email to nudge an abandoned signup to finish. */
    public function sendReminder(Request $request, Response $response, array $args): Response
    {
        $app = $this->applications->findById((int) $args['id']);
        $body = (array) $request->getParsedBody();
        if ($app === null || !Csrf::validate($body['csrf'] ?? null) || $app['status'] !== 'draft') {
            return $response->withStatus(302)->withHeader('Location', '/admin/demandes/abandonnees');
        }

        $this->remind($app, $request);
        $admin = $request->getAttribute('user');
        $this->audit($admin['email'], 'application.reminder_sent', (int) $app['id']);

        return $response->withStatus(302)->withHeader('Location', '/admin/demandes/abandonnees');
    }

    /** Permanently deletes an abandoned draft (application, people, documents, attestations, uploaded files). */
    public function clear(Request $request, Response $response, array $args): Response
    {
        $app = $this->applications->findById((int) $args['id']);
        $body = (array) $request->getParsedBody();
        if ($app === null || !Csrf::validate($body['csrf'] ?? null) || $app['status'] !== 'draft') {
            return $response->withStatus(302)->withHeader('Location', '/admin/demandes/abandonnees');
        }

        $admin = $request->getAttribute('user');
        $this->audit($admin['email'], 'application.cleared', (int) $app['id']);
        $this->clearApp($app);

        return $response->withStatus(302)->withHeader('Location', '/admin/demandes/abandonnees');
    }

    /** Group-action counterpart of sendReminder() — same reminder, applied to a checked selection. */
    public function bulkRemind(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/demandes/abandonnees');
        }

        $admin = $request->getAttribute('user');
        foreach ($this->draftsFromIds((array) ($body['ids'] ?? [])) as $app) {
            $this->remind($app, $request);
            $this->audit($admin['email'], 'application.reminder_sent', (int) $app['id']);
        }

        return $response->withStatus(302)->withHeader('Location', '/admin/demandes/abandonnees');
    }

    /** Group-action counterpart of clear() — same permanent delete, applied to a checked selection. */
    public function bulkClear(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/demandes/abandonnees');
        }

        $admin = $request->getAttribute('user');
        foreach ($this->draftsFromIds((array) ($body['ids'] ?? [])) as $app) {
            $this->audit($admin['email'], 'application.cleared', (int) $app['id']);
            $this->clearApp($app);
        }

        return $response->withStatus(302)->withHeader('Location', '/admin/demandes/abandonnees');
    }

    /**
     * Resolves checked ids to their still-draft application rows, silently
     * skipping anything missing or no longer draft — a checkbox left stale
     * from a page the admin had open a while shouldn't fail the whole batch.
     *
     * @param array $ids
     * @return array[]
     */
    private function draftsFromIds(array $ids): array
    {
        $apps = [];
        foreach (array_map('intval', $ids) as $id) {
            $app = $this->applications->findById($id);
            if ($app !== null && $app['status'] === 'draft') {
                $apps[] = $app;
            }
        }
        return $apps;
    }

    private function remind(array $app, Request $request): void
    {
        $link = $this->baseUrl($request) . '/inscription/' . $app['token'] . '/formule';
        $this->mailer->send(
            $app['email'],
            'Terminez votre demande d\'adhésion — Bad & Squash',
            '<p>Bonjour,</p><p>Vous avez commencé une demande d\'adhésion mais ne l\'avez pas terminée.</p>'
            . '<p>Vous pouvez la reprendre à tout moment via ce lien :</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Reprendre ma demande</a></p>',
            'application_reminder',
        );
    }

    private function clearApp(array $app): void
    {
        $this->uploads->deleteAll((int) $app['id']);
        $this->applications->delete((int) $app['id']);
    }

    /**
     * Grants a one-off exception: a Hors-commune applicant gets Midi
     * eligibility at the Garennois price. Usable any time before the
     * validate/reject decision, independent of it, so an admin can grant it
     * proactively even before the applicant has switched to Midi.
     */
    public function grantMidiOverride(Request $request, Response $response, array $args): Response
    {
        $app = $this->applications->findById((int) $args['id']);
        $body = (array) $request->getParsedBody();
        if ($app === null || !Csrf::validate($body['csrf'] ?? null) || $app['status'] !== 'submitted' || $app['residence'] !== 'hors-commune') {
            return $response->withStatus(302)->withHeader('Location', '/admin/demandes');
        }

        $reason = trim((string) ($body['midi_override_reason'] ?? ''));
        if ($reason === '') {
            return $response->withStatus(302)->withHeader('Location', '/admin/demandes/' . $app['id']);
        }

        $this->applications->update((int) $app['id'], [
            'midi_residency_override'        => 1,
            'midi_residency_override_reason' => mb_substr($reason, 0, 500),
        ]);
        $admin = $request->getAttribute('user');
        $this->audit($admin['email'], 'application.midi_override_grant', (int) $app['id'], ['reason' => $reason]);

        return $response->withStatus(302)->withHeader('Location', '/admin/demandes/' . $app['id']);
    }

    /** Streams an uploaded document (or generated attestation PDF) to an admin. */
    public function document(Request $request, Response $response, array $args): Response
    {
        $app = $this->applications->findById((int) $args['id']);
        $storedName = basename((string) $args['file']); // basename() blocks any traversal
        $path = $app !== null ? $this->uploads->dirFor((int) $app['id']) . '/' . $storedName : '';

        $known = $app !== null && (
            in_array($storedName, array_column($this->applications->documents((int) $app['id']), 'stored_name'), true)
            || in_array($storedName, array_column($this->applications->attestations((int) $app['id']), 'pdf_stored_name'), true)
        );
        if (!$known || !is_file($path)) {
            return $response->withStatus(404);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'inline; filename="' . $storedName . '"')
            ->withBody(new Stream(fopen($path, 'rb')));
    }

    private function quoteFor(array $app): ?Quote
    {
        if ($app['subscription_type'] === '') {
            return null;
        }
        $season = new Season((int) $app['season_start_year']);
        $now = new DateTimeImmutable();
        $people = $this->applications->people((int) $app['id']);
        $isCouple = (bool) $app['is_couple'];
        $quotePeople = $isCouple
            ? [
                ['competitor' => (bool) $people[1]['competitor'], 'licenceRemoved' => (bool) $people[1]['licence_removed']],
                ['competitor' => (bool) ($people[2]['competitor'] ?? false), 'licenceRemoved' => (bool) ($people[2]['licence_removed'] ?? false)],
            ]
            : [['competitor' => (bool) $people[1]['competitor'], 'licenceRemoved' => (bool) $people[1]['licence_removed']]];

        return $this->pricing->quote(
            $app['subscription_type'],
            $app['residence'],
            premiere: true,
            season: $season,
            joinDate: $season->contains($now) ? $now : null,
            isCouple: $isCouple,
            people: $quotePeople,
            lessonsCount: (int) $app['lessons_count'],
            midiResidencyOverride: (bool) $app['midi_residency_override'],
            summerPack: (bool) $app['summer_pack'],
            studentDiscount: (bool) $app['student_discount_requested'],
        );
    }

    private function audit(string $actor, string $action, int $applicationId, array $details = []): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO audit_log (actor, action, entity, entity_id, details, created_at)
             VALUES (?, ?, "application", ?, ?, NOW())'
        );
        $stmt->execute([$actor, $action, (string) $applicationId, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }

    private function baseUrl(Request $request): string
    {
        $uri = $request->getUri();
        return $uri->getScheme() . '://' . $uri->getAuthority();
    }
}
