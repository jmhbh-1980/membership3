<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CreditNoteRepository;
use App\Repository\ResidenceExceptionRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\BalleJauneException;
use App\Service\CreditNoteService;
use App\Service\Mailer;
use App\Service\PricingService;
use App\Service\Season;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Logger;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/**
 * Residence pricing exceptions for members (the renewal side). The join side
 * lives on the application itself — see
 * AdminApplicationController::grantResidenceException() — because an applicant
 * has no BJ user yet.
 *
 * A member never asks for this in the app: there is no request form and no
 * approval queue, because there is nothing for them to submit. The club decides
 * offline and an admin records the decision here, ideally before the member
 * opens their renewal, so they simply see the right price. The renewal flow
 * re-reads the grant on every request, so granting it while the member is
 * mid-flow takes effect on their next page load.
 *
 * Grants last one season and must be re-granted deliberately, which is what
 * this list is for at each rollover.
 */
final class AdminResidenceExceptionController
{
    public function __construct(
        private readonly ResidenceExceptionRepository $exceptions,
        private readonly CreditNoteRepository $creditNotes,
        private readonly CreditNoteService $creditNoteService,
        private readonly BalleJauneClient $bj,
        private readonly PricingService $pricing,
        private readonly Mailer $mailer,
        private readonly PhpRenderer $renderer,
        private readonly Db $db,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Every exception for a season, with what it costs. The euro figure is the
     * point of the page: granted one at a time these are invisible, and a list
     * with a total at the bottom is what lets the bureau see the scale.
     */
    public function index(Request $request, Response $response): Response
    {
        $season = $this->seasonFrom($request);
        $rows = [];
        $total = 0.0;

        foreach ($this->exceptions->forSeason($season->startYear) as $exception) {
            $bjUserId = (int) $exception['bj_user_id'];
            $row = [
                'exception' => $exception,
                'name'      => $this->nameFor($bjUserId),
                'residence' => $this->residenceFor($bjUserId),
                'cost'      => null,
                'creditNote' => null,
                'settled'   => false,
            ];

            $order = $this->creditNoteService->settledOrderFor($season->startYear, $bjUserId);
            if ($order !== null) {
                $row['settled'] = true;
                $row['creditNote'] = $this->creditNotes->findByOrderId((int) $order['id']);
                $assessment = $this->creditNoteService->assess($order, (string) $exception['pricing_residence']);
                $row['cost'] = $assessment;
                if ($exception['revoked_at'] === null) {
                    $total += $assessment['amount'];
                }
            }
            $rows[] = $row;
        }

        return $this->renderer->render($response, 'pages/admin_residence_exceptions.php', [
            'title'   => 'Exceptions de tarif — saison ' . $season->label(),
            'csrf'    => Csrf::token(),
            'season'  => $season,
            'seasons' => $this->seasonChoices($season),
            'rows'    => $rows,
            'total'   => round($total, 2),
        ]);
    }

    /** The grant/revoke screen for one member. */
    public function member(Request $request, Response $response, array $args): Response
    {
        $bjUserId = (int) $args['id'];
        $season = $this->seasonFrom($request);

        try {
            $bjUser = $this->bj->get('users/' . $bjUserId)['user'];
        } catch (BalleJauneException) {
            return $response->withStatus(302)->withHeader('Location', '/admin/exceptions-tarif');
        }

        $residence = $this->pricing->residenceForZip((string) ($bjUser['postalcode'] ?? ''));
        $exception = $this->exceptions->find($season->startYear, $bjUserId);
        $active = $exception !== null && $exception['revoked_at'] === null ? $exception : null;

        // The exception is always "the grid you are not on" — with only two
        // grids, offering a free choice would just allow granting a no-op.
        $grantable = $residence === PricingService::RESIDENCE_GARENNOIS
            ? PricingService::RESIDENCE_HORS_COMMUNE
            : PricingService::RESIDENCE_GARENNOIS;

        $order = $this->creditNoteService->settledOrderFor($season->startYear, $bjUserId);
        $creditNote = $order !== null ? $this->creditNotes->findByOrderId((int) $order['id']) : null;
        $assessment = $order !== null
            ? $this->creditNoteService->assess($order, $active['pricing_residence'] ?? $grantable)
            : null;

        return $this->renderer->render($response, 'pages/admin_residence_exception_member.php', [
            'title'      => 'Exception de tarif — ' . trim(($bjUser['lastname'] ?? '') . ' ' . ($bjUser['firstname'] ?? '')),
            'csrf'       => Csrf::token(),
            'season'     => $season,
            'seasons'    => $this->seasonChoices($season),
            'bjUser'     => $bjUser,
            'residence'  => $residence,
            'grantable'  => $grantable,
            'exception'  => $exception,
            'active'     => $active,
            'order'      => $order,
            'creditNote' => $creditNote,
            'assessment' => $assessment,
        ]);
    }

    public function decide(Request $request, Response $response, array $args): Response
    {
        $bjUserId = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        $season = new Season((int) ($body['season'] ?? 0));
        $back = '/admin/exceptions-tarif/membre/' . $bjUserId . '?saison=' . $season->startYear;

        if (!Csrf::validate($body['csrf'] ?? null) || $season->startYear <= 0) {
            return $response->withStatus(302)->withHeader('Location', '/admin/exceptions-tarif');
        }

        $admin = $request->getAttribute('user');
        $reason = trim((string) ($body['reason'] ?? ''));
        $action = (string) ($body['action'] ?? '');

        if ($action === 'revoke') {
            $this->exceptions->revoke($season->startYear, $bjUserId, (string) $admin['email'], $reason);
            $this->audit((string) $admin['email'], 'residence_exception.revoke', $bjUserId, [
                'season' => $season->startYear, 'reason' => $reason,
            ]);
            return $response->withStatus(302)->withHeader('Location', $back);
        }

        if ($action === 'grant') {
            if ($reason === '') {
                return $response->withStatus(302)->withHeader('Location', $back . '&erreur=motif');
            }
            $granted = (string) ($body['pricing_residence'] ?? '');
            if (!in_array($granted, [PricingService::RESIDENCE_GARENNOIS, PricingService::RESIDENCE_HORS_COMMUNE], true)) {
                return $response->withStatus(302)->withHeader('Location', $back);
            }
            $this->exceptions->grant($season->startYear, $bjUserId, $granted, $reason, (string) $admin['email']);
            $this->audit((string) $admin['email'], 'residence_exception.grant', $bjUserId, [
                'season' => $season->startYear, 'pricing_residence' => $granted, 'reason' => $reason,
            ]);
            return $response->withStatus(302)->withHeader('Location', $back);
        }

        // Issuing the avoir is a separate, deliberate second step: granting the
        // exception never moves money on its own.
        if ($action === 'credit_note') {
            $active = $this->exceptions->findActive($season->startYear, $bjUserId);
            $order = $this->creditNoteService->settledOrderFor($season->startYear, $bjUserId);
            if ($active === null || $order === null) {
                return $response->withStatus(302)->withHeader('Location', $back);
            }

            $creditNote = $this->creditNoteService->issue(
                $order,
                (string) $active['pricing_residence'],
                $reason !== '' ? $reason : (string) $active['reason'],
                (string) $admin['email'],
            );
            if ($creditNote === null) {
                return $response->withStatus(302)->withHeader('Location', $back . '&erreur=avoir');
            }

            $this->audit((string) $admin['email'], 'residence_exception.credit_note', $bjUserId, [
                'season' => $season->startYear, 'number' => $creditNote['number'], 'amount' => $creditNote['amount'],
            ]);
            $this->notifyCreditNote($order, $creditNote);

            return $response->withStatus(302)->withHeader('Location', $back);
        }

        return $response->withStatus(302)->withHeader('Location', $back);
    }

    private function notifyCreditNote(array $order, array $creditNote): void
    {
        $email = (string) $order['email'];
        if ($email === '') {
            return;
        }
        $amount = number_format((float) $creditNote['amount'], 2, ',', ' ');
        $this->mailer->send(
            $email,
            'Avoir ' . $creditNote['number'] . ' — Bad & Squash',
            '<p>Bonjour,</p>'
            . '<p>Le club vous a accordé un tarif préférentiel pour cette saison, après le règlement de votre adhésion.</p>'
            . '<p>Vous trouverez ci-joint l\'avoir correspondant, d\'un montant de <strong>' . $amount . ' €</strong>. '
            . 'Ce montant vous sera remboursé par le club.</p>',
            'credit_note_issued',
            [$this->creditNoteService->attachmentFor($creditNote)],
        );
    }

    /** Seasons offered in the picker: those that already have grants, plus the current and next one. */
    private function seasonChoices(Season $current): array
    {
        $now = Season::fromDate(new DateTimeImmutable());
        $years = array_unique(array_merge(
            $this->exceptions->seasonsWithGrants(),
            [$now->startYear, $now->next()->startYear, $current->startYear],
        ));
        rsort($years);
        return array_map(fn (int $y) => new Season($y), $years);
    }

    private function seasonFrom(Request $request): Season
    {
        $year = (int) ($request->getQueryParams()['saison'] ?? 0);
        return $year > 0 ? new Season($year) : Season::fromDate(new DateTimeImmutable());
    }

    private function nameFor(int $bjUserId): string
    {
        try {
            $user = $this->bj->get('users/' . $bjUserId)['user'];
            return trim(($user['lastname'] ?? '') . ' ' . ($user['firstname'] ?? ''));
        } catch (BalleJauneException) {
            return '#' . $bjUserId;
        }
    }

    private function residenceFor(int $bjUserId): string
    {
        try {
            $user = $this->bj->get('users/' . $bjUserId)['user'];
            return $this->pricing->residenceForZip((string) ($user['postalcode'] ?? ''));
        } catch (BalleJauneException) {
            return '';
        }
    }

    private function audit(string $actor, string $action, int $bjUserId, array $details = []): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO audit_log (actor, action, entity, entity_id, details, created_at)
             VALUES (?, ?, "bj_user", ?, ?, NOW())'
        );
        $stmt->execute([$actor, $action, (string) $bjUserId, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }
}
