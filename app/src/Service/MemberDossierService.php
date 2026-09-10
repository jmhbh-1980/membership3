<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ApplicationRepository;
use App\Repository\CreditNoteRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Repository\ResidenceExceptionRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\BalleJauneException;
use App\Service\BalleJaune\RoleResolver;
use App\Service\BalleJaune\SubscriptionResolver;
use App\Support\Db;
use DateTimeImmutable;

/**
 * Everything the club holds about one member, assembled for the admin dossier
 * at /admin/membres/{id}. Read-only: it aggregates and links, and never writes
 * or decides — the pages that own each action keep owning it.
 *
 * Identity is the Balle Jaune user id, because BJ is the source of truth for
 * who a member is. Two consequences worth knowing before reading the output:
 *
 *  - Documents (photo, justificatif, certificats) only exist for a member who
 *    joined *through this app*: they hang off applications, and the link back
 *    to a BJ member is written by fulfillment onto application_people. Anyone
 *    who joined before the app existed, or was created by hand in BJ, has none
 *    and never will. dossier['hasApplication'] says which case you are in, so
 *    the page can state that rather than look broken.
 *
 *  - The timeline is assembled from timestamps scattered across a dozen tables,
 *    because nothing in this app stores a status history. It is therefore
 *    honest but incomplete: admin decisions, emails, orders, invoices and the
 *    things a member explicitly signs are all recorded, while a member opening
 *    a payment page or abandoning a checkout leaves no trace anywhere. A gap in
 *    the timeline means "not recorded", not "nothing happened", and the page
 *    says so.
 */
final class MemberDossierService
{
    /**
     * Audit action slugs in plain French. The slugs are developer identifiers
     * and read as noise on an admin page, but an unmapped one still shows
     * verbatim rather than being hidden — a missing label should look untidy,
     * never make an event disappear from someone's history.
     */
    private const array AUDIT_LABELS = [
        'application.validate'                  => 'Demande d\'adhésion validée',
        'application.reject'                    => 'Demande d\'adhésion refusée',
        'application.cleared'                   => 'Demande effacée',
        'application.reminder_sent'             => 'Relance envoyée (reprendre la demande)',
        'application.payment_reminder_sent'     => 'Relance envoyée (lien de paiement)',
        'application.residence_exception_grant' => 'Exception de tarif accordée sur la demande',
        'application.residence_exception_revoke' => 'Exception de tarif révoquée sur la demande',
        'application_attestation.signed'        => 'Attestation de santé signée',
        'auth.admin_login'                      => 'Connexion administrateur',
        'auth.impersonate_start'                => 'Début de consultation « voir comme »',
        'auth.impersonate_stop'                 => 'Fin de consultation « voir comme »',
        'bank_transfer.confirm'                 => 'Virement confirmé',
        'bank_transfer.reject'                  => 'Virement refusé',
        'change_request.approve'                => 'Changement de formule approuvé',
        'change_request.refuse'                 => 'Changement de formule refusé',
        'change_request.revoke'                 => 'Changement de formule annulé',
        'licence.unflag'                        => 'Licence enregistrée auprès de la fédération',
        'order.cancel'                          => 'Commande annulée',
        'order.duplicate_payment'               => 'Paiement en double détecté',
        'order.fulfilled'                       => 'Commande finalisée',
        'order.fulfillment_failed'              => 'Échec de finalisation',
        'order.process'                         => 'Commande marquée traitée',
        'order.refund'                          => 'Commande remboursée',
        'promo_order.approve'                   => 'Code promo approuvé',
        'promo_order.refuse'                    => 'Code promo refusé',
        'reglement_interieur.accepted'          => 'Règlement intérieur accepté',
        'renewal_attestation.signed'            => 'Attestation de santé signée',
        'residence_exception.credit_note'       => 'Avoir émis (exception de tarif)',
        'residence_exception.grant'             => 'Exception de tarif accordée',
        'residence_exception.revoke'            => 'Exception de tarif révoquée',
        'shoes.approve'                         => 'Contrôle des semelles validé — compte activé',
        'shoes_policy.accepted'                 => 'Règles chaussures acceptées',
        'student_discount.confirm'              => 'Statut étudiant validé',
        'student_discount.reject'               => 'Statut étudiant refusé',
    ];

    public function __construct(
        private readonly BalleJauneClient $bj,
        private readonly SubscriptionResolver $subscriptions,
        private readonly RoleResolver $roles,
        private readonly ApplicationRepository $applications,
        private readonly OrderRepository $orders,
        private readonly InvoiceRepository $invoices,
        private readonly CreditNoteRepository $creditNotes,
        private readonly ResidenceExceptionRepository $residenceExceptions,
        private readonly PricingService $pricing,
        private readonly Db $db,
    ) {
    }

    /** @return ?array the full dossier, or null when BJ doesn't know this user */
    public function forBjUser(int $bjUserId): ?array
    {
        try {
            $bjUser = $this->bj->get('users/' . $bjUserId)['user'] ?? null;
        } catch (BalleJauneException) {
            return null;
        }
        if ($bjUser === null) {
            return null;
        }

        $applications = $this->applications->findByBjUserId($bjUserId);
        $orders = $this->orders->allForBjUser($bjUserId);
        $invoicesByOrder = $this->keyBy($this->invoices->findForBjUser($bjUserId), 'order_id');
        $creditNotesByOrder = $this->keyBy($this->creditNotes->findForBjUser($bjUserId), 'order_id');

        foreach ($orders as &$order) {
            $order['invoice'] = $invoicesByOrder[(int) $order['id']] ?? null;
            $order['creditNote'] = $creditNotesByOrder[(int) $order['id']] ?? null;
        }
        unset($order);

        $residence = $this->pricing->residenceForZip((string) ($bjUser['postalcode'] ?? ''));
        $season = Season::fromDate(new DateTimeImmutable());
        $exception = $this->residenceExceptions->findActive($season->startYear, $bjUserId);

        $documents = [];
        foreach ($applications as $app) {
            $documents[(int) $app['id']] = [
                'application'  => $app,
                'documents'    => $this->applications->documents((int) $app['id']),
                'attestations' => $this->applications->attestations((int) $app['id']),
            ];
        }

        $emails = $this->emailsFor($bjUser);

        return [
            'bjUser'           => $bjUser,
            'subscriptionName' => array_search((int) ($bjUser['subscription_id'] ?? 0), $this->subscriptions->map(), true) ?: '',
            'roleName'         => $this->roleName($bjUser),
            'residence'        => $residence,
            'pricingResidence' => PricingService::pricingResidence($residence, (string) ($exception['pricing_residence'] ?? '')),
            'exception'        => $exception,
            'hasApplication'   => $applications !== [],
            'documentsByApplication' => $documents,
            'orders'           => $orders,
            'seasons'          => $this->seasons($bjUserId),
            'lessons'          => $this->rows('SELECT * FROM lesson_enrollments WHERE bj_user_id = ? ORDER BY season_start_year DESC', [$bjUserId]),
            'installmentPlans' => $this->rows('SELECT * FROM installment_plans WHERE bj_user_id = ? ORDER BY season_start_year DESC', [$bjUserId]),
            'changeRequests'   => $this->rows('SELECT * FROM change_requests WHERE bj_user_id = ? ORDER BY created_at DESC', [$bjUserId]),
            'exceptions'       => $this->rows('SELECT * FROM residence_exceptions WHERE bj_user_id = ? ORDER BY season_start_year DESC', [$bjUserId]),
            'emails'           => $emails,
            'timeline'         => $this->timeline($bjUserId, $applications, $orders, $emails),
        ];
    }

    /**
     * Every dated fact about this member, newest first. Assembled rather than
     * read: see the class docblock for why it is necessarily incomplete.
     *
     * @return list<array{at:string, kind:string, label:string, detail:string, actor:string, url:?string}>
     */
    private function timeline(int $bjUserId, array $applications, array $orders, array $emails): array
    {
        $entries = [];
        $add = function (?string $at, string $kind, string $label, string $detail = '', string $actor = '', ?string $url = null) use (&$entries): void {
            if ($at === null || $at === '' || str_starts_with($at, '0000')) {
                return;
            }
            $entries[] = compact('at', 'kind', 'label', 'detail', 'actor', 'url');
        };

        $audit = $this->auditEntries($bjUserId, $applications, $orders);

        // Several events are recorded twice: once as a timestamp on the row
        // (applications.validated_at) and once as an audit entry. The audit one
        // is strictly better — it names who did it — so the derived duplicate is
        // suppressed wherever the audit entity_id identifies the same object
        // unambiguously. Not attempted for residence exceptions or credit notes,
        // whose audit rows key on the member rather than the grant, so skipping
        // one could hide a genuine second decision.
        $logged = [];
        foreach ($audit as $log) {
            $logged[$log['action'] . '|' . $log['entity_id']] = true;
        }
        $hasAudit = static fn (string $action, int $entityId): bool => isset($logged[$action . '|' . $entityId]);

        foreach ($applications as $app) {
            $appId = (int) $app['id'];
            $url = '/admin/demandes/' . $appId;
            $add($app['created_at'], 'application', 'Demande d\'adhésion commencée', 'demande #' . $appId, '', $url);
            $add($app['submitted_at'], 'application', 'Demande envoyée au club', 'demande #' . $appId, '', $url);
            if (!$hasAudit('application.validate', $appId)) {
                $add($app['validated_at'], 'application', 'Demande validée', 'demande #' . $appId, '', $url);
            }
            if ($app['status'] === 'rejected' && !$hasAudit('application.reject', $appId)) {
                $add($app['updated_at'], 'application', 'Demande refusée', (string) $app['rejection_reason'], '', $url);
            }
        }

        foreach ($orders as $order) {
            $orderId = (int) $order['id'];
            $amount = number_format((float) $order['amount'], 2, ',', ' ') . ' €';
            $url = '/admin/commandes/' . $orderId;
            $add($order['created_at'], 'order', 'Commande créée', $order['kind'] . ' — ' . $amount, '', $url);
            if (!$hasAudit('order.fulfilled', $orderId)) {
                $add($order['fulfilled_at'] ?? null, 'order', 'Commande finalisée', $amount, '', $url);
            }
            if (($order['invoice'] ?? null) !== null) {
                $add($order['invoice']['issued_at'], 'invoice', 'Facture émise', (string) $order['invoice']['number'], '', $url);
            }
            if (($order['creditNote'] ?? null) !== null) {
                $add(
                    $order['creditNote']['issued_at'],
                    'credit_note',
                    'Avoir émis',
                    $order['creditNote']['number'] . ' — ' . number_format((float) $order['creditNote']['amount'], 2, ',', ' ') . ' €',
                    (string) $order['creditNote']['issued_by'],
                    $url,
                );
            }
        }

        foreach ($this->seasons($bjUserId) as $formula) {
            $add(
                $formula['created_at'],
                'season',
                'Saison ' . (int) $formula['season_start_year'] . '-' . ((int) $formula['season_start_year'] + 1) . ' enregistrée',
                trim($formula['subscription_type'] . ($formula['is_couple'] ? ' — couple' : '') . ($formula['lessons'] > 0 ? ' — cours × ' . (int) $formula['lessons'] : '')),
            );
        }

        foreach ($this->rows('SELECT * FROM change_requests WHERE bj_user_id = ?', [$bjUserId]) as $req) {
            $add($req['created_at'], 'change_request', 'Changement demandé', (string) $req['subscription_type'], '', '/admin/changements');
            $add($req['decided_at'], 'change_request', 'Changement ' . ($req['status'] === 'approved' ? 'approuvé' : $req['status']), (string) $req['admin_note'], '', '/admin/changements');
        }

        foreach ($this->rows('SELECT * FROM residence_exceptions WHERE bj_user_id = ?', [$bjUserId]) as $exc) {
            $label = 'Exception de tarif accordée (' . (int) $exc['season_start_year'] . ')';
            $add($exc['granted_at'], 'exception', $label, (string) $exc['reason'], (string) $exc['granted_by'], '/admin/exceptions-tarif/membre/' . $bjUserId);
            $add($exc['revoked_at'], 'exception', 'Exception de tarif révoquée', (string) $exc['revoke_reason'], (string) $exc['revoked_by'], '/admin/exceptions-tarif/membre/' . $bjUserId);
        }

        foreach ($this->rows('SELECT * FROM renewal_student_certificates WHERE bj_user_id = ?', [$bjUserId]) as $cert) {
            $add($cert['requested_at'], 'student', 'Certificat de scolarité transmis', '', '', '/admin/reduction-etudiant');
            $add($cert['decided_at'], 'student', 'Statut étudiant ' . ($cert['status'] === 'approved' ? 'validé' : 'refusé'), (string) $cert['refusal_reason'], (string) $cert['decided_by']);
        }

        foreach ($this->rows('SELECT * FROM renewal_attestations WHERE bj_user_id = ?', [$bjUserId]) as $att) {
            $add($att['signed_at'] ?: $att['created_at'], 'attestation', 'Attestation de santé', $att['outcome'] === 'certificate' ? 'certificat médical fourni' : 'questionnaire signé');
        }

        foreach ($this->rows('SELECT * FROM installment_plans WHERE bj_user_id = ?', [$bjUserId]) as $plan) {
            $add($plan['created_at'], 'installment', 'Paiement échelonné mis en place', (int) $plan['installment_count'] . ' versements', '', '/admin/paiements-echelonnes');
        }

        foreach ($this->rows('SELECT * FROM lesson_enrollments WHERE bj_user_id = ?', [$bjUserId]) as $lesson) {
            $add($lesson['created_at'], 'lessons', 'Inscrit aux cours collectifs', 'saison ' . (int) $lesson['season_start_year'], '', '/admin/cours');
        }

        foreach ($audit as $log) {
            $action = (string) $log['action'];
            $add($log['created_at'], 'audit', self::AUDIT_LABELS[$action] ?? $action, $this->auditDetail($log), (string) $log['actor']);
        }

        foreach ($emails as $email) {
            $add(
                $email['created_at'],
                'email',
                'Email — ' . $email['subject'],
                $email['status'] === 'failed' ? 'échec d\'envoi : ' . (string) $email['error'] : 'envoyé',
            );
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
        return $entries;
    }

    /**
     * Audit rows touching this member. The log keys on four different entity
     * types, so all four have to be asked for by the ids this member owns —
     * querying only entity='bj_user' would silently drop every order and
     * application decision made about them.
     */
    private function auditEntries(int $bjUserId, array $applications, array $orders): array
    {
        $clauses = ['(entity = "bj_user" AND entity_id = ?)'];
        $params = [(string) $bjUserId];

        foreach ([['application', array_column($applications, 'id')], ['order', array_column($orders, 'id')]] as [$entity, $ids]) {
            if ($ids === []) {
                continue;
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $clauses[] = "(entity = \"{$entity}\" AND entity_id IN ({$in}))";
            $params = [...$params, ...array_map('strval', $ids)];
        }

        $changeRequestIds = array_column($this->rows('SELECT id FROM change_requests WHERE bj_user_id = ?', [$bjUserId]), 'id');
        if ($changeRequestIds !== []) {
            $in = implode(',', array_fill(0, count($changeRequestIds), '?'));
            $clauses[] = "(entity = \"renewal\" AND entity_id IN ({$in}))";
            $params = [...$params, ...array_map('strval', $changeRequestIds)];
        }

        return $this->rows('SELECT * FROM audit_log WHERE ' . implode(' OR ', $clauses), $params);
    }

    private function auditDetail(array $log): string
    {
        $details = json_decode((string) ($log['details'] ?? ''), true);
        if (!is_array($details) || $details === []) {
            return '';
        }
        $parts = [];
        foreach ($details as $key => $value) {
            $parts[] = $key . ' : ' . (is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE));
        }
        return implode(' · ', $parts);
    }

    /**
     * Emails sent to this member, matched on every address BJ holds for them —
     * a member who gave a second address receives on both, and matching only
     * the primary would hide half the trail.
     */
    private function emailsFor(array $bjUser): array
    {
        $addresses = array_values(array_filter(array_unique([
            mb_strtolower(trim((string) ($bjUser['email'] ?? ''))),
            mb_strtolower(trim((string) ($bjUser['email2'] ?? ''))),
        ])));
        if ($addresses === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($addresses), '?'));
        return $this->rows(
            "SELECT * FROM email_log WHERE LOWER(recipient) IN ({$in}) ORDER BY created_at DESC LIMIT 200",
            $addresses,
        );
    }

    private function seasons(int $bjUserId): array
    {
        return $this->rows(
            'SELECT * FROM member_formulas WHERE bj_user_id = ? ORDER BY season_start_year DESC',
            [$bjUserId],
        );
    }

    private function roleName(array $bjUser): string
    {
        foreach (['Administrateur', 'Membre', 'Visiteur'] as $name) {
            try {
                if ((int) ($bjUser['acl_id'] ?? 0) === $this->roles->idForName($name)) {
                    return $name;
                }
            } catch (\Throwable) {
                return '';
            }
        }
        return '';
    }

    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<int, array> */
    private function keyBy(array $rows, string $column): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row[$column]] = $row;
        }
        return $keyed;
    }
}
