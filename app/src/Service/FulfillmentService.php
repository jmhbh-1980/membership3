<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ApplicationRepository;
use App\Repository\OrderRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\RoleResolver;
use App\Service\BalleJaune\SubscriptionResolver;
use App\Support\Logger;
use DateTimeImmutable;

/**
 * Turns a paid order into Balle Jaune reality. Runs behind the atomic
 * paid→fulfilling order transition, so it executes exactly once per order
 * even when the SumUp webhook and the return URL both fire.
 *
 * New members are created with the "Visiteur" ACL (booking-restricted): the
 * BJ API has no suspend field, so activation = promoting to "Membre", done
 * by an admin once the shoes check passes (payment already being confirmed).
 */
class FulfillmentService
{
    private const array LICENCE_LABELS = ['pass' => 'Pass', 'federale' => 'fédérale', 'jeune' => 'jeune', 'ete' => 'été'];

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly OrderRepository $orders,
        private readonly BalleJauneClient $bj,
        private readonly SubscriptionResolver $subscriptions,
        private readonly RoleResolver $roles,
        private readonly PricingService $pricing,
        private readonly RenewalService $renewals,
        private readonly Mailer $mailer,
        private readonly Logger $logger,
        private readonly InvoiceService $invoices,
    ) {
    }

    /**
     * Fulfills a paid order. Caller must have won the paid→fulfilling
     * transition. Throws on hard failure (order is put back to 'paid' by
     * the caller so fulfillment can be retried).
     */
    public function fulfill(array $order): void
    {
        match ($order['kind']) {
            'join'    => $this->fulfillJoin($order),
            'renewal' => $this->fulfillRenewal($order),
            'credits' => $this->fulfillCredits($order),
            default   => throw new \RuntimeException("Type de commande non géré : {$order['kind']}"),
        };
    }

    private function fulfillCredits(array $order): void
    {
        $meta = json_decode((string) ($order['meta'] ?? '{}'), true) ?: [];
        $tickets = (int) ($meta['tickets'] ?? 0);
        if ($tickets <= 0) {
            throw new \RuntimeException('Commande crédits sans quantité (order ' . $order['id'] . ')');
        }

        $bjUserId = (int) $order['bj_user_id'];
        $user = $this->bj->get('users/' . $bjUserId)['user'];
        $newBalance = (float) ($user['book_card_tickets'] ?? 0) + $tickets;
        $this->bj->patch('users/' . $bjUserId, ['book_card_tickets' => $newBalance]);

        $this->logger->info('fulfillment', 'Credits added', [
            'bj_user_id' => $bjUserId, 'tickets' => $tickets, 'new_balance' => $newBalance,
        ]);

        $this->mailer->send(
            $order['email'],
            'Crédits ajoutés — Bad & Squash',
            '<p>Bonjour,</p><p>Votre paiement de ' . number_format((float) $order['amount'], 2, ',', ' ') . ' € a bien été reçu : '
            . $tickets . ' crédits ont été ajoutés à votre compte.</p>'
            . '<p>Nouveau solde : <strong>' . number_format($newBalance, 1, ',', ' ') . '</strong> crédit(s).</p>',
            'credits_fulfilled',
        );
    }

    private function fulfillRenewal(array $order): void
    {
        $meta = json_decode((string) ($order['meta'] ?? '{}'), true) ?: [];
        $season = new Season((int) $meta['seasonStartYear']);
        $subscription = $this->pricing->subscription($meta['subscriptionType'], $season);
        $subscriptionId = $this->subscriptions->idForName($subscription['bj_subscription']);
        $isCouple = (bool) ($meta['isCouple'] ?? false);

        $userIds = [(int) $order['bj_user_id']];
        if ($isCouple && (int) ($meta['partnerBjUserId'] ?? 0) > 0) {
            $userIds[] = (int) $meta['partnerBjUserId'];
        }

        $count = count($userIds);
        $shareCents = (int) round($order['amount'] * 100 / $count);
        $billingUser = null; // captured at $i === 0, for the invoice's billing address

        foreach ($userIds as $i => $bjUserId) {
            $amountShare = $i === 0
                ? ($order['amount'] * 100 - $shareCents * ($count - 1)) / 100
                : $shareCents / 100;

            $competitor = (bool) ($i === 0 ? ($meta['competitor'] ?? false) : ($meta['partnerCompetitor'] ?? false));
            $licenceRemoved = (bool) ($i === 0 ? ($meta['licenceRemoved'] ?? false) : ($meta['partnerLicenceRemoved'] ?? false));
            $licenceRemovalReason = (string) ($i === 0 ? ($meta['licenceRemovalReason'] ?? '') : ($meta['partnerLicenceRemovalReason'] ?? ''));

            $licenceKind = !empty($meta['lateSettlement']) ? 'ete' : $this->pricing->licenceKindFor($subscription['audience'], $competitor);
            $notes = 'Renouvellement en ligne #' . $order['id']
                . (!empty($meta['transactionCode']) ? ' (' . $meta['transactionCode'] . ')' : '')
                . ' — ' . $subscription['label']
                . ' | saison ' . $season->label()
                . ($count > 1 ? ' | Couple — total réglé ' . number_format((float) $order['amount'], 2, ',', ' ') . ' € pour 2' : '')
                . ((int) ($meta['lessons'] ?? 0) > 0 ? ' | Cours collectifs × ' . (int) $meta['lessons'] : '')
                . ($licenceRemoved
                    ? ' | Licence retirée — motif : ' . $licenceRemovalReason
                    : ' | Licence : ' . self::LICENCE_LABELS[$licenceKind]);

            $partnerOf = $count > 1 ? $userIds[1 - $i] : 0;

            $currentUser = $this->bj->get('users/' . $bjUserId)['user'];
            if ($i === 0) {
                $billingUser = $currentUser;
            }

            // Cumulate onto whatever's already in subscription_notes rather than
            // overwriting it — this field can hold a member's whole renewal
            // history (and anything staff typed in by hand there), which every
            // renewal used to silently wipe. Newest note first, oldest trimmed
            // off the tail if the combined text exceeds BJ's field length.
            $existingNotes = trim((string) ($currentUser['subscription_notes'] ?? ''));
            $combinedNotes = $existingNotes !== '' ? $notes . "\n" . $existingNotes : $notes;

            $patch = [
                'subscription_id'          => $subscriptionId,
                'subscription_date_end'    => $season->graceEnd()->format('Y-m-d'),
                'subscription_paid'        => true,
                'subscription_paid_date'   => date('Y-m-d'),
                'subscription_paid_amount' => round($amountShare, 2),
                'subscription_notes'       => mb_substr($combinedNotes, 0, 1000),
                'flag'                     => true, // licence to (re-)register for the new season
                // custom2/custom3: BJ is the couple-status/partner-linkage source of
                // truth (see RenewalService::resolveCoupleStatus()) — every renewal
                // re-asserts it here, self-healing any drift from the local cache.
                'custom2'                  => $isCouple ? '1' : '',
                'custom3'                  => $partnerOf > 0 ? (string) $partnerOf : '',
            ];

            // subscription_date_start tracks tenure, not the current formula: only
            // backfill it when BJ has no prior value, and never write a future date.
            $currentStart = (string) ($currentUser['subscription_date_start'] ?? '');
            if ($currentStart === '' || $currentStart === '0000-00-00') {
                $patch['subscription_date_start'] = $season->startCappedAt(new DateTimeImmutable())->format('Y-m-d');
            }

            $this->bj->patch('users/' . $bjUserId, $patch);

            $this->renewals->recordFormula(
                $season->startYear,
                $bjUserId,
                $meta['subscriptionType'],
                $isCouple,
                $competitor,
                (int) ($meta['lessons'] ?? 0),
                $partnerOf,
                (int) $order['id'],
            );

            $this->logger->info('fulfillment', 'BJ user renewed', ['bj_user_id' => $bjUserId, 'season' => $season->label()]);
        }

        // Lessons: first user when lessons >= 1, partner when 2 (adults only).
        $lessons = (int) ($meta['lessons'] ?? 0);
        foreach ($userIds as $i => $bjUserId) {
            if ($lessons >= $i + 1 && $subscription['audience'] !== 'jeune') {
                $user = $this->bj->get('users/' . $bjUserId)['user'] ?? [];
                $this->orders->addLessonEnrollment(
                    $season->startYear,
                    $bjUserId,
                    (string) ($user['firstname'] ?? ''),
                    (string) ($user['lastname'] ?? ''),
                    (string) ($user['email'] ?? ''),
                    (int) $order['id'],
                );
            }
        }

        if (!empty($meta['changeRequestId'])) {
            $this->renewals->completeChangeRequest((int) $meta['changeRequestId']);
        }

        $invoiceAttachments = [];
        try {
            $contextPeople = $isCouple
                ? [
                    ['competitor' => (bool) ($meta['competitor'] ?? false), 'licenceRemoved' => (bool) ($meta['licenceRemoved'] ?? false)],
                    ['competitor' => (bool) ($meta['partnerCompetitor'] ?? false), 'licenceRemoved' => (bool) ($meta['partnerLicenceRemoved'] ?? false)],
                ]
                : [['competitor' => (bool) ($meta['competitor'] ?? false), 'licenceRemoved' => (bool) ($meta['licenceRemoved'] ?? false)]];
            $context = [
                'subscription'    => $subscription,
                'subscriptionKey' => $meta['subscriptionType'],
                'season'          => $season,
                'residence'       => $meta['residence'],
                'summerPack'      => !empty($meta['lateSettlement']),
                'people'          => $contextPeople,
                'billingName'     => trim(($billingUser['firstname'] ?? '') . ' ' . ($billingUser['lastname'] ?? '')),
                'billingAddress'  => [
                    'address'    => $billingUser['address'] ?? '',
                    'postalcode' => $billingUser['postalcode'] ?? '',
                    'city'       => $billingUser['city'] ?? '',
                ],
            ];
            $invoice = $this->invoices->generateForOrder($order, $context);
            if ($invoice !== null) {
                $invoiceAttachments[] = $this->invoices->attachmentFor($invoice);
            }
        } catch (\Throwable $e) {
            $this->logger->error('fulfillment', 'Invoice step failed, continuing without attachment', [
                'order_id' => $order['id'], 'error' => $e->getMessage(),
            ]);
        }

        $this->mailer->send(
            $order['email'],
            'Renouvellement confirmé — Bad & Squash',
            '<p>Bonjour,</p><p>Votre renouvellement pour la saison ' . $season->label() . ' est confirmé '
            . '(' . number_format((float) $order['amount'], 2, ',', ' ') . ' € réglés). Bonne saison !</p>',
            'renewal_fulfilled',
            $invoiceAttachments,
        );
    }


    private function fulfillJoin(array $order): void
    {
        $app = $this->applications->findById((int) $order['application_id']);
        if ($app === null) {
            throw new \RuntimeException('Application introuvable pour la commande ' . $order['id']);
        }

        $people = $this->applications->people((int) $app['id']);
        $attestations = $this->applications->attestations((int) $app['id']);
        $season = new Season((int) $app['season_start_year']);
        $subscription = $this->pricing->subscription($app['subscription_type'], $season);

        $subscriptionId = $this->subscriptions->idForName($subscription['bj_subscription']);
        $visitorAclId = $this->roles->idForName('Visiteur');

        $count = count($people);
        $shareCents = (int) round($order['amount'] * 100 / $count);

        foreach ($people as $position => $person) {
            $amountShare = $position === 1
                ? ($order['amount'] * 100 - $shareCents * ($count - 1)) / 100
                : $shareCents / 100;

            $notes = $this->buildNotes($app, $subscription, $order, $person, $count);

            $payload = [
                'firstname'               => $person['firstname'],
                'lastname'                => $person['lastname'],
                'email'                   => $person['email'] !== '' ? $person['email'] : $app['email'],
                'sex'                     => $person['sex'],
                'birthday'                => $person['birthdate'],
                'lang'                    => 'fr_FR',
                'phone1'                  => $person['phone'],
                'address'                 => $person['address'],
                'postalcode'              => $person['postalcode'],
                'city'                    => $person['city'],
                'country'                 => 'FR',
                'acl_id'                  => $visitorAclId,
                'subscription_id'         => $subscriptionId,
                'subscription_date_start' => $season->start()->format('Y-m-d'),
                'subscription_date_end'   => $season->graceEnd()->format('Y-m-d'),
                'subscription_paid'       => true,
                'subscription_paid_date'  => date('Y-m-d'),
                'subscription_paid_amount' => round($amountShare, 2),
                'subscription_notes'      => mb_substr($notes, 0, 1000),
                'flag'                    => true, // licence to register with the federation
            ];

            if (!empty($person['is_minor'])) {
                $payload['custom1'] = GuardianContact::format($person['guardian_fullname'], $person['guardian_email'], $person['guardian_phone']);
                if (isset($attestations[$position])) {
                    $attestation = $attestations[$position];
                    $payload['medical_certificate'] = date('Y-m-d');
                    $payload['medical_certificate_comment'] = $attestation['outcome'] === 'all_negative'
                        ? 'Attestation QS-Sport signée en ligne le ' . date('d/m/Y', strtotime($attestation['signed_at']))
                        : 'Certificat médical fourni à l\'inscription (voir dossier)';
                }
            }

            $created = $this->bj->post('users', $payload);
            $bjUserId = (int) ($created['user']['user_id'] ?? 0);
            if ($bjUserId <= 0) {
                throw new \RuntimeException('Création Balle Jaune échouée pour ' . $person['firstname'] . ' ' . $person['lastname']);
            }

            $this->applications->setPersonBjUserId((int) $app['id'], (int) $position, $bjUserId);

            $this->logger->info('fulfillment', 'BJ user created', [
                'application_id' => (int) $app['id'], 'position' => $position, 'bj_user_id' => $bjUserId,
            ]);

            // Group lessons: applicant when lessons_count >= 1, partner when 2.
            if ((int) $app['lessons_count'] >= (int) $position && $subscription['audience'] !== 'jeune') {
                $this->orders->addLessonEnrollment(
                    (int) $app['season_start_year'],
                    $bjUserId,
                    $person['firstname'],
                    $person['lastname'],
                    $person['email'] !== '' ? $person['email'] : $app['email'],
                    (int) $order['id'],
                );
            }
        }

        // Record each member's subscription app-side (used by future renewals).
        $people = $this->applications->people((int) $app['id']);
        foreach ($people as $position => $person) {
            $partnerOf = count($people) > 1 ? (int) ($people[3 - $position]['bj_user_id'] ?? 0) : 0;
            if ((int) $person['bj_user_id'] > 0) {
                // custom2/custom3: BJ is the couple-status/partner-linkage source of
                // truth (see RenewalService::resolveCoupleStatus()). Both people's BJ
                // ids only exist once this loop runs, so this is a follow-up PATCH
                // rather than part of the POST above. Nothing to clear for singles —
                // a brand-new BJ user already has blank custom fields.
                if ($partnerOf > 0) {
                    $this->bj->patch('users/' . $person['bj_user_id'], ['custom2' => '1', 'custom3' => (string) $partnerOf]);
                }
                $this->renewals->recordFormula(
                    (int) $app['season_start_year'],
                    (int) $person['bj_user_id'],
                    $app['subscription_type'],
                    (bool) $app['is_couple'],
                    (bool) $person['competitor'],
                    (int) $app['lessons_count'],
                    $partnerOf,
                    (int) $order['id'],
                );
            }
        }

        $this->applications->setStatus((int) $app['id'], 'fulfilled');

        $invoiceAttachments = [];
        try {
            $isCouple = (bool) $app['is_couple'];
            $contextPeople = $isCouple
                ? [
                    ['competitor' => (bool) $people[1]['competitor'], 'licenceRemoved' => (bool) $people[1]['licence_removed']],
                    ['competitor' => (bool) ($people[2]['competitor'] ?? false), 'licenceRemoved' => (bool) ($people[2]['licence_removed'] ?? false)],
                ]
                : [['competitor' => (bool) $people[1]['competitor'], 'licenceRemoved' => (bool) $people[1]['licence_removed']]];
            // Billing name/address come from Balle Jaune (the just-created account),
            // not the application form — BJ is the source of truth for member data.
            $billingUser = $this->bj->get('users/' . $people[1]['bj_user_id'])['user'];
            $context = [
                'subscription'    => $subscription,
                'subscriptionKey' => $app['subscription_type'],
                'season'          => $season,
                'residence'       => $app['residence'],
                'summerPack'      => (bool) $app['summer_pack'],
                'people'          => $contextPeople,
                'billingName'     => trim(($billingUser['firstname'] ?? '') . ' ' . ($billingUser['lastname'] ?? '')),
                'billingAddress'  => [
                    'address'    => $billingUser['address'] ?? '',
                    'postalcode' => $billingUser['postalcode'] ?? '',
                    'city'       => $billingUser['city'] ?? '',
                ],
            ];
            $invoice = $this->invoices->generateForOrder($order, $context);
            if ($invoice !== null) {
                $invoiceAttachments[] = $this->invoices->attachmentFor($invoice);
            }
        } catch (\Throwable $e) {
            $this->logger->error('fulfillment', 'Invoice step failed, continuing without attachment', [
                'order_id' => $order['id'], 'error' => $e->getMessage(),
            ]);
        }

        $this->mailer->send(
            $app['email'],
            'Bienvenue au club ! — Bad & Squash',
            '<p>Bonjour,</p><p>Votre paiement de ' . number_format((float) $order['amount'], 2, ',', ' ') . ' € a bien été reçu : bienvenue au club !</p>'
            . '<p>Vos identifiants de réservation Balle Jaune vous parviennent par email séparé.</p>'
            . '<p><strong>Dernière étape :</strong> lors de votre première venue, présentez-vous à l\'accueil avec vos chaussures de salle '
            . '(semelles non marquantes) pour activer définitivement votre compte.</p>',
            'join_fulfilled',
            $invoiceAttachments,
        );
    }

    private function buildNotes(array $app, array $subscription, array $order, array $person, int $count): string
    {
        $parts = [
            'Adhésion en ligne #' . $order['id'] . ' — ' . $subscription['label'],
            'Tarif ' . $app['residence'] . ', 1ère inscription, saison ' . $app['season_start_year'] . '-' . ((int) $app['season_start_year'] + 1),
        ];
        if ($count > 1) {
            $parts[] = 'Couple — total réglé ' . number_format((float) $order['amount'], 2, ',', ' ') . ' € pour 2 personnes';
        }
        if ((int) $app['lessons_count'] > 0) {
            $parts[] = 'Cours collectifs × ' . (int) $app['lessons_count'];
        }
        if (!empty($person['licence_removed'])) {
            $parts[] = 'Licence retirée du panier — motif : ' . $person['licence_removal_reason'];
        } else {
            $kind = !empty($app['summer_pack']) ? 'ete' : $this->pricing->licenceKindFor($subscription['audience'], (bool) $person['competitor']);
            $parts[] = 'Licence : ' . self::LICENCE_LABELS[$kind];
        }
        return implode(' | ', $parts);
    }

}
