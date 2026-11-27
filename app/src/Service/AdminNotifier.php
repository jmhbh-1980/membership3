<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OrderRepository;
use App\Support\Logger;

/**
 * Emails the club the moment something waits on an admin, so nobody has to
 * check the dashboard to find out: the five approvals that make up the
 * pending decisions queue (PendingDecisionsService — a join application, a
 * formula or licence change, a promo code, a student discount, a bank
 * transfer the payer says is sent), and the payment incidents only an admin
 * can resolve (paid but not fulfilled, paid twice, an installment declined).
 *
 * Left out on purpose, like on the pending decisions board: licences to
 * register with the federation (a batch chore nobody is blocked on) and the
 * first-welcome appointment, which the new member books on WhatsApp
 * (JoinWelcome) — that message already reaches the club.
 *
 * One address (settings' club.admin_notifications), subjects all starting
 * with "[Action requise]" so they can be filtered, and absolute links, since
 * an email has no page to be relative to. A notification is never worth
 * breaking the member's flow for: a failure is logged and swallowed.
 * Not final, so the services that notify can be tested with a mock.
 */
class AdminNotifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Logger $logger,
        private readonly string $to,
        private readonly string $publicUrl,
    ) {
    }

    public function applicationSubmitted(int $applicationId, string $who, string $what): void
    {
        $this->send(
            'application_submitted',
            'Demande d\'adhésion à valider — ' . $who,
            [$who . ' vient d\'envoyer sa demande d\'adhésion (' . $what . ').',
             'Le paiement ne lui sera demandé qu\'une fois la demande validée.'],
            '/admin/demandes/' . $applicationId,
            'Examiner la demande',
        );
    }

    /** A renewal held for approval: a formula or couple change, or a licence taken out ($licence). */
    public function changeRequested(string $who, bool $licence, string $what = ''): void
    {
        $this->send(
            'change_request',
            ($licence ? 'Demande de licence à valider — ' : 'Changement de formule à valider — ') . $who,
            [$licence
                ? $who . ' demande le retrait de sa licence (ou de celle de son conjoint) pour son renouvellement.'
                : $who . ' demande un changement de formule pour son renouvellement' . ($what !== '' ? ' : ' . $what : '') . '.',
             'Son renouvellement attend votre décision.'],
            '/admin/changements',
            'Décider',
        );
    }

    /** An order parked until an admin approves its promo code ('promo') or student discount ('student'). */
    public function approvalRequested(string $kind, array $order, string $who): void
    {
        $amount = self::amount($order);
        $student = $kind === 'student';
        $this->send(
            $student ? 'student_approval' : 'promo_approval',
            ($student ? 'Réduction étudiant à valider — ' : 'Code promo à valider — ') . $who,
            [$student
                ? $who . ' demande la réduction étudiant (commande #' . (int) $order['id'] . ', ' . $amount . ') : son certificat de scolarité est à vérifier.'
                : $who . ' a utilisé un code promo qui demande votre accord (commande #' . (int) $order['id'] . ', ' . $amount . ').',
             'Le paiement attend votre validation.'],
            $student ? '/admin/reduction-etudiant' : '/admin/codes-promo/approbations',
            $student ? 'Vérifier le certificat' : 'Valider ou refuser le code',
        );
    }

    public function bankTransferClaimed(array $order): void
    {
        $this->send(
            'bank_transfer_claimed',
            'Virement signalé — commande #' . (int) $order['id'],
            ['Un adhérent indique avoir fait le virement pour ' . self::kindLabel($order)
                . ' (commande #' . (int) $order['id'] . ', ' . self::amount($order) . ').',
             'Référence attendue : ' . OrderRepository::bankTransferReference($order) . '.'],
            '/admin/virements',
            'Vérifier sur le relevé bancaire',
        );
    }

    public function fulfillmentFailed(array $order, string $error): void
    {
        $this->send(
            'fulfillment_failed',
            'Paiement reçu mais commande non finalisée — #' . (int) $order['id'],
            ['Le paiement de la commande #' . (int) $order['id'] . ' (' . self::kindLabel($order) . ', ' . self::amount($order)
                . ') a bien été reçu, mais sa finalisation a échoué : ' . mb_substr($error, 0, 300),
             'Elle sera retentée au prochain signal de paiement. Vérifiez sur sa page qu\'elle a bien abouti.'],
            '/admin/commandes/' . (int) $order['id'],
            'Voir la commande',
        );
    }

    public function duplicatePayment(array $order): void
    {
        $this->send(
            'duplicate_payment',
            'Paiement en double — commande #' . (int) $order['id'],
            ['La commande #' . (int) $order['id'] . ' (' . self::kindLabel($order) . ', ' . self::amount($order)
                . ') a été payée alors qu\'une autre commande avait déjà réglé la même chose.',
             'Un remboursement est très probablement dû.'],
            '/admin/commandes/' . (int) $order['id'],
            'Voir la commande',
        );
    }

    public function installmentFailed(int $bjUserId, string $who, float $amount): void
    {
        $this->send(
            'installment_failed',
            'Échec de prélèvement — ' . $who,
            ['Le prélèvement automatique du versement de ' . number_format($amount, 2, ',', ' ') . ' € de ' . $who . ' n\'a pas abouti.',
             'Son échéancier est interrompu ; l\'adhérent a été invité à contacter le club pour régulariser.'],
            '/admin/membres/' . $bjUserId,
            'Voir sa fiche',
        );
    }

    /** @param string[] $lines plain text, escaped here */
    private function send(string $type, string $title, array $lines, string $path, string $linkLabel): void
    {
        if ($this->to === '') {
            return;
        }
        $link = static fn (string $url, string $label): string
            => '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
        $body = implode('', array_map(static fn (string $l): string => '<p>' . htmlspecialchars($l, ENT_QUOTES) . '</p>', $lines))
            . '<p><strong>' . $link($this->publicUrl . $path, $linkLabel) . '</strong></p>'
            . '<p style="color:#666666;font-size:13px;">Tout ce qui attend une décision : '
            . $link($this->publicUrl . '/admin/decisions', $this->publicUrl . '/admin/decisions') . '</p>';

        try {
            $this->mailer->send($this->to, '[Action requise] ' . $title, $body, 'admin_' . $type);
        } catch (\Throwable $e) {
            $this->logger->error('admin-notify', 'Notification admin non envoyée', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    private static function amount(array $order): string
    {
        return number_format((float) $order['amount'], 2, ',', ' ') . ' €';
    }

    private static function kindLabel(array $order): string
    {
        return match ((string) ($order['kind'] ?? '')) {
            'join'    => 'une adhésion',
            'renewal' => 'un renouvellement',
            'credits' => 'des crédits de jeu',
            'lessons' => 'des cours collectifs',
            'change'  => 'un changement de formule',
            default   => 'une commande',
        };
    }
}
