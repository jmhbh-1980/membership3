<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ApplicationRepository;
use App\Repository\OrderRepository;
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
    /** Approved by the club, money not in yet — see awaitingPayment(). */
    private const array AWAITING_PAYMENT_STATUSES = ['validated', 'awaiting_payment'];

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly OrderRepository $orders,
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

    /**
     * Approved but not yet paid — the gap between /admin/demandes (awaiting the
     * club's decision) and a fulfilled order. Validating an application used to
     * remove it from every admin screen: no queue, no counter, and for a
     * 'validated' row not even an order to find it by, since the order is only
     * created once the applicant opens the payment page.
     *
     * Each row carries whatever order is blocking it, so the list distinguishes
     * "hasn't clicked the link" from "waiting on a bank transfer *you* have to
     * confirm" — the second is the club's own move, not the applicant's.
     */
    public function awaitingPayment(Request $request, Response $response): Response
    {
        $rows = [];
        foreach ($this->applications->approvedAwaitingPayment() as $app) {
            $rows[] = [
                'app'    => $app,
                'people' => $this->applications->people((int) $app['id']),
                'order'  => $this->orders->latestUnsettledForApplication((int) $app['id']),
            ];
        }

        return $this->renderer->render($response, 'pages/admin_applications_awaiting_payment.php', [
            'title' => 'Approuvées, en attente de paiement',
            'csrf'  => Csrf::token(),
            'rows'  => $rows,
        ]);
    }

    /** Re-sends the payment-link email to an approved applicant who hasn't paid. */
    public function sendPaymentReminder(Request $request, Response $response, array $args): Response
    {
        $app = $this->applications->findById((int) $args['id']);
        $body = (array) $request->getParsedBody();
        if ($app === null || !Csrf::validate($body['csrf'] ?? null) || !in_array($app['status'], self::AWAITING_PAYMENT_STATUSES, true)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/demandes/attente-paiement');
        }

        $this->remindPayment($app, $request);
        $admin = $request->getAttribute('user');
        $this->audit($admin['email'], 'application.payment_reminder_sent', (int) $app['id']);

        return $response->withStatus(302)->withHeader('Location', '/admin/demandes/attente-paiement');
    }

    /** Group-action counterpart of sendPaymentReminder(). */
    public function bulkRemindPayment(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/demandes/attente-paiement');
        }

        $admin = $request->getAttribute('user');
        foreach (array_map('intval', (array) ($body['ids'] ?? [])) as $id) {
            $app = $this->applications->findById($id);
            // Same tolerance as draftsFromIds(): a checkbox left stale on a page
            // the admin had open (they paid in the meantime) is skipped, not fatal.
            if ($app === null || !in_array($app['status'], self::AWAITING_PAYMENT_STATUSES, true)) {
                continue;
            }
            $this->remindPayment($app, $request);
            $this->audit($admin['email'], 'application.payment_reminder_sent', $id);
        }

        return $response->withStatus(302)->withHeader('Location', '/admin/demandes/attente-paiement');
    }

    /**
     * The payment link, not the wizard-resume link remind() sends: these
     * applicants finished the wizard and were approved, so sending them back
     * into the form would be the wrong nudge entirely. Mirrors the email
     * decide() sends on validation.
     */
    private function remindPayment(array $app, Request $request): void
    {
        $link = $this->baseUrl($request) . '/paiement/' . $app['token'];
        $this->mailer->send(
            $app['email'],
            'Votre adhésion vous attend — finalisez le paiement',
            '<p>Bonjour,</p><p>Votre demande d\'adhésion a été validée par le club, mais le paiement n\'a pas encore été finalisé.</p>'
            . '<p>Pour terminer votre adhésion :</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Payer mon adhésion</a></p>'
            . '<p>Si vous rencontrez un problème, répondez simplement à cet email.</p>',
            'application_payment_reminder',
        );
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
     * Grants (or revokes) a residence pricing exception on this application:
     * the applicant is read at the other price grid — normally a Hors-commune
     * applicant at the Garennois one, occasionally the reverse when a 92250
     * address doesn't hold up. Usable any time before the validate/reject
     * decision and independent of it, so an admin can grant it proactively,
     * before the applicant has even picked a formula.
     *
     * Applies to every subscription type. Midi is simply the one with no
     * hors-commune bucket, so granting the exception is also what makes it
     * selectable — which is all the old Midi-only override ever did.
     *
     * The applicant has no BJ user yet, so the grant lives on the application;
     * FulfillmentService copies it into residence_exceptions once the BJ user
     * exists, and the renewal flow picks it up from there next season.
     */
    public function grantResidenceException(Request $request, Response $response, array $args): Response
    {
        $app = $this->applications->findById((int) $args['id']);
        $body = (array) $request->getParsedBody();
        if ($app === null || !Csrf::validate($body['csrf'] ?? null) || $app['status'] !== 'submitted') {
            return $response->withStatus(302)->withHeader('Location', '/admin/demandes');
        }
        $redirect = $response->withStatus(302)->withHeader('Location', '/admin/demandes/' . $app['id']);

        $admin = $request->getAttribute('user');
        $reason = trim((string) ($body['reason'] ?? ''));

        if (!empty($body['revoke'])) {
            if ((string) $app['pricing_residence'] === '') {
                return $redirect;
            }
            $this->applications->update((int) $app['id'], [
                'pricing_residence'        => '',
                'pricing_residence_reason' => '',
                'pricing_residence_by'     => '',
            ]);
            $this->resetIneligibleSubscription($app, (string) $app['residence']);
            $this->audit($admin['email'], 'application.residence_exception_revoke', (int) $app['id'], ['reason' => $reason]);
            return $redirect;
        }

        // The exception is always "the grid you are not on" — offering a free
        // choice would let an admin grant a no-op, and there are only two grids.
        $granted = $app['residence'] === PricingService::RESIDENCE_GARENNOIS
            ? PricingService::RESIDENCE_HORS_COMMUNE
            : PricingService::RESIDENCE_GARENNOIS;

        if ($reason === '') {
            return $redirect;
        }

        $this->applications->update((int) $app['id'], [
            'pricing_residence'        => $granted,
            'pricing_residence_reason' => mb_substr($reason, 0, 500),
            'pricing_residence_by'     => $admin['email'],
        ]);
        $this->resetIneligibleSubscription($app, $granted);
        $this->audit($admin['email'], 'application.residence_exception_grant', (int) $app['id'], [
            'pricing_residence' => $granted,
            'reason'            => $reason,
        ]);

        return $redirect;
    }

    /**
     * Clears an already-chosen formula that the new grid doesn't offer, so the
     * applicant is sent back to pick again rather than hitting quote()'s
     * "n'est pas ouvert aux résidents ..." exception on the next page load.
     * Only Midi can be affected today (it is the sole Garennois-only formula),
     * but this is derived from the catalogue rather than hardcoded to it.
     * Tickets are sold at one price to everyone, so no grid can rule them out.
     */
    private function resetIneligibleSubscription(array $app, string $pricingResidence): void
    {
        $key = (string) $app['subscription_type'];
        if ($key === '' || $key === PricingService::TICKETS) {
            return;
        }
        $season = new Season((int) $app['season_start_year']);
        if (!isset($this->pricing->subscriptionsFor($pricingResidence, $season)[$key])) {
            $this->applications->update((int) $app['id'], ['subscription_type' => '', 'lessons_count' => 0]);
        }
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
            PricingService::pricingResidence((string) $app['residence'], (string) $app['pricing_residence']),
            premiere: true,
            season: $season,
            joinDate: $season->contains($now) ? $now : null,
            isCouple: $isCouple,
            people: $quotePeople,
            lessonsCount: (int) $app['lessons_count'],
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
