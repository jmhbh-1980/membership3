<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Service\AttestationPdfService;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\SubscriptionResolver;
use App\Service\GuardianContact;
use App\Service\PricingService;
use App\Service\PromoCodeService;
use App\Service\Quote;
use App\Service\RenewalService;
use App\Service\Season;
use App\Service\SumUpService;
use App\Service\UploadService;
use App\Support\Csrf;
use App\Support\Logger;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/**
 * Member renewal flow. Same subscription (and couple status) as the current
 * one → straight to payment; a change requires an admin-approved change
 * request. Competitor status is an independent toggle that never needs
 * approval — it only affects which licence kind is derived, not the BJ
 * subscription. Renewals migrate members from legacy BJ subscriptions to
 * the simplified "_" subscriptions at fulfillment.
 */
final class RenewalController
{
    public function __construct(
        private readonly BalleJauneClient $bj,
        private readonly SubscriptionResolver $subscriptions,
        private readonly PricingService $pricing,
        private readonly PromoCodeService $promoCodes,
        private readonly RenewalService $renewals,
        private readonly OrderRepository $orders,
        private readonly SumUpService $sumup,
        private readonly AttestationPdfService $attestationPdf,
        private readonly UploadService $uploads,
        private readonly PhpRenderer $renderer,
        private readonly Logger $logger,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $context = $this->context($request);

        if ($request->getQueryParams()['choice'] ?? null) {
            // context() above already persisted it into $_SESSION['renewal_choice'] —
            // redirect to a clean URL so a refresh doesn't reprocess the query string.
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }

        if ($context['choiceAvailable']) {
            return $this->renderer->render($response, 'pages/renewal_status.php', [
                'title'   => 'Renouvellement',
                'state'   => 'choice',
                'season'  => $context['season'],
                'request' => null,
                'steps'   => null,
            ]);
        }

        if ($context['redirect'] !== null) {
            // BJ has resolved one way or another (or this is an unrelated redirect
            // state) — a "just paid, confirming" marker has nothing left to do.
            unset($_SESSION['renewal_just_paid']);
            $steps = in_array($context['redirect'], ['change_pending', 'change_approved'], true)
                ? $this->renewalSteps($this->isMinor($context['bjUser']), true, $context['redirect'] === 'change_pending' ? 'validation' : 'paiement')
                : null;
            return $this->renderer->render($response, 'pages/renewal_status.php', [
                'title' => 'Renouvellement',
                'state' => $context['redirect'],
                'season' => $context['season'],
                'request' => $context['changeRequest'],
                'steps' => $steps,
            ]);
        }

        if ($this->justPaidForCurrentSeason($context['season']->startYear)) {
            return $this->renderer->render($response, 'pages/renewal_status.php', [
                'title'   => 'Renouvellement',
                'state'   => 'confirming',
                'season'  => $context['season'],
                'request' => null,
                'steps'   => null,
            ]);
        }

        return $this->renderForm($response, $context, [], []);
    }

    public function submit(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        $body = (array) $request->getParsedBody();
        if ($context['redirect'] !== null || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }

        $errors = [];
        $subscriptionKey = (string) ($body['subscription'] ?? '');
        if (!isset($context['subscriptions'][$subscriptionKey])) {
            $errors[] = 'Merci de choisir un abonnement.';
        }

        $subscription = $context['subscriptions'][$subscriptionKey] ?? null;
        $isCouple = $subscription !== null && !empty($body['is_couple']) && !empty($subscription['couple_available']) && !$context['lateSettlement'];
        $competitor = !empty($body['competitor']);
        $partnerCompetitor = !empty($body['partner_competitor']);
        $lessons = 0;
        $partnerBjUserId = 0;

        if ($subscription !== null) {
            // No group-lesson add-on on a late-settlement renewal — catching up an
            // almost-over season, not signing up for a fresh year of lessons.
            if ($subscription['audience'] !== 'jeune' && !$context['lateSettlement']) {
                $lessons = min((int) !empty($body['lessons_1']), 1) + ($isCouple ? (int) !empty($body['lessons_2']) : 0);
            }
            if ($isCouple) {
                $partnerBjUserId = (int) $context['currentPartnerBjUserId'];
                if ($partnerBjUserId === 0) {
                    $partnerEmail = mb_strtolower(trim((string) ($body['partner_email'] ?? '')));
                    $partner = $partnerEmail !== '' ? $this->findBjUserByEmail($partnerEmail) : null;
                    if ($partner === null) {
                        $errors[] = 'Conjoint(e) introuvable : indiquez l\'adresse email de son compte adhérent.';
                    } elseif ((int) $partner['user_id'] === $context['bjUser']['user_id']) {
                        $errors[] = 'L\'email du/de la conjoint(e) doit être différent du vôtre.';
                    } else {
                        $partnerBjUserId = (int) $partner['user_id'];
                    }
                }
            }
        }

        if ($errors !== []) {
            return $this->renderForm($response, $context, $body, $errors);
        }

        // A subscription-tier or couple-status change needs admin approval;
        // changing competitor status alone does not (it only shifts which
        // licence kind is derived, not the BJ subscription).
        $sameSubscription = $context['currentSubscriptionType'] !== null
            && $subscriptionKey === $context['currentSubscriptionType']
            && $isCouple === $context['currentIsCouple'];

        if (!$sameSubscription) {
            $user = $context['bjUser'];
            $this->renewals->createChangeRequest(
                (int) $user['user_id'],
                (string) $user['email'],
                trim($user['firstname'] . ' ' . $user['lastname']),
                $context['currentLabel'],
                $subscriptionKey,
                $isCouple,
                $competitor,
                $lessons,
                (string) ($body['partner_email'] ?? ''),
                $context['season']->startYear,
            );
            $this->logger->info('renewal', 'Change request created', ['bj_user_id' => $user['user_id'], 'subscription' => $subscriptionKey]);
            if ($this->isMinor($user)) {
                return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement/sante');
            }
            return $this->renderer->render($response, 'pages/renewal_status.php', [
                'title'   => 'Renouvellement',
                'state'   => 'change_pending',
                'season'  => $context['season'],
                'request' => null,
                'steps'   => $this->renewalSteps(false, true, 'validation'),
            ]);
        }

        // An already-approved licence-waiver request (context()'s redirect check above
        // already ruled out a still-pending one) carries forward here — otherwise
        // resubmitting this formula step would silently clobber it back to false.
        $approvedLicenceWaiver = $this->renewals->pendingChangeRequest((int) $context['bjUser']['user_id'], $context['season']->startYear);

        $_SESSION['renewal_intent'] = [
            'subscriptionType'            => $subscriptionKey,
            'isCouple'                    => $isCouple,
            'competitor'                  => $competitor,
            'partnerCompetitor'           => $partnerCompetitor,
            'lessons'                     => $lessons,
            'partnerBjUserId'             => $partnerBjUserId,
            'seasonStartYear'             => $context['season']->startYear,
            'residence'                   => $context['residence'],
            'licenceRemoved'              => (bool) ($approvedLicenceWaiver['licence_removed'] ?? false),
            'licenceRemovalReason'        => $approvedLicenceWaiver['licence_removal_reason'] ?? '',
            'partnerLicenceRemoved'       => (bool) ($approvedLicenceWaiver['partner_licence_removed'] ?? false),
            'partnerLicenceRemovalReason' => $approvedLicenceWaiver['partner_licence_removal_reason'] ?? '',
            'midiResidencyOverride'       => $context['midiResidencyOverride'],
            'lateSettlement'              => $context['lateSettlement'],
            'promoCode'                   => '',
        ];
        if ($this->isMinor($context['bjUser'])) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement/sante');
        }
        return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement/paiement');
    }

    // ── Annual health questionnaire (minors only) ────────────────────────

    public function showSante(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        if (!$this->isMinor($context['bjUser'])) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }
        return $this->renderSante($response, $context, [], []);
    }

    public function submitSante(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        if (!$this->isMinor($context['bjUser'])) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $this->renderSante($response, $context, $body, ['Session expirée, merci de réessayer.']);
        }

        $bjUser = $context['bjUser'];
        $bjUserId = (int) $bjUser['user_id'];
        $seasonStartYear = $context['season']->startYear;
        $outcome = (string) ($body['outcome'] ?? '');
        $errors = [];

        if ($outcome === 'all_negative') {
            $guardian = trim((string) ($body['guardian_fullname'] ?? ''));
            $place = trim((string) ($body['place'] ?? ''));
            if ($guardian === '') {
                $errors[] = 'Le nom du représentant légal est requis.';
            }
            if ($place === '') {
                $errors[] = 'Le lieu de signature est requis.';
            }
            if (empty($body['read_questionnaire'])) {
                $errors[] = 'Vous devez confirmer que le questionnaire de santé a été renseigné.';
            }
            if ($errors === []) {
                try {
                    $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';
                    $pdfName = $this->attestationPdf->generate(
                        'renewals/' . $seasonStartYear . '/' . $bjUserId,
                        ['firstname' => $bjUser['firstname'] ?? '', 'lastname' => $bjUser['lastname'] ?? '', 'birthdate' => $bjUser['birthday'] ?? ''],
                        $guardian,
                        '',
                        $place,
                        (string) ($body['signature'] ?? ''),
                        $ip,
                    );
                    $this->renewals->saveAttestation($seasonStartYear, $bjUserId, [
                        'outcome'              => 'all_negative',
                        'guardian_fullname'    => $guardian,
                        'signature_ip'         => $ip,
                        'signed_at'            => date('Y-m-d H:i:s'),
                        'document_stored_name' => $pdfName,
                    ]);
                } catch (\RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        } elseif ($outcome === 'certificate') {
            $files = $request->getUploadedFiles();
            $file = $files['medical_certificate'] ?? null;
            if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Merci de joindre le certificat médical (moins de 6 mois).';
            } else {
                try {
                    $stored = $this->uploads->storeRenewalCertificate($file, $seasonStartYear, $bjUserId);
                    $this->renewals->saveAttestation($seasonStartYear, $bjUserId, [
                        'outcome'              => 'certificate',
                        'document_stored_name' => $stored['storedName'],
                    ]);
                } catch (\RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        } else {
            $errors[] = 'Merci de choisir une option.';
        }

        if ($errors !== []) {
            return $this->renderSante($response, $context, $body, $errors);
        }

        return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement/representant-legal');
    }

    // ── Guardian contact update (minors only, optional) ──────────────────

    public function showGuardian(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        if (!$this->isMinor($context['bjUser'])) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }
        $old = GuardianContact::parse((string) ($context['bjUser']['custom1'] ?? ''));
        return $this->renderGuardian($response, $context, $old, []);
    }

    public function submitGuardian(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        if (!$this->isMinor($context['bjUser'])) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }
        $body = (array) $request->getParsedBody();
        $fullname = trim((string) ($body['guardian_fullname'] ?? ''));
        $email = trim((string) ($body['guardian_email'] ?? ''));
        $phone = trim((string) ($body['guardian_phone'] ?? ''));
        $old = ['fullname' => $fullname, 'email' => $email, 'phone' => $phone];
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $this->renderGuardian($response, $context, $old, ['Session expirée, merci de réessayer.']);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->renderGuardian($response, $context, $old, ['Email du représentant légal invalide.']);
        }

        $contact = GuardianContact::format($fullname, $email, $phone);
        if ($contact !== (string) ($context['bjUser']['custom1'] ?? '')) {
            $this->bj->patch('users/' . $context['bjUser']['user_id'], ['custom1' => $contact]);
        }

        $next = isset($_SESSION['renewal_intent']) ? '/espace/renouvellement/paiement' : '/espace/renouvellement';
        return $response->withStatus(302)->withHeader('Location', $next);
    }

    public function showCart(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        $intent = $this->intent($context);
        if ($intent === null || !$this->canReachCart($context)) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }
        if ($this->isMinor($context['bjUser']) && $this->renewals->attestationFor($context['season']->startYear, (int) $context['bjUser']['user_id']) === null) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement/sante');
        }
        return $this->renderCart($response, $context, $intent, []);
    }

    /**
     * Cart options: per-person licence removal and group-lesson enrolments.
     * Competitor status (and thus licence kind) is fixed at the subscription
     * step, not editable here. Requesting to waive a licence for the first
     * time needs admin approval (a change_requests row, kind='licence') —
     * reverting an already-effective waiver back to full price never does,
     * since that only ever benefits the club.
     */
    public function updateOptions(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        $intent = $this->intent($context);
        $body = (array) $request->getParsedBody();
        if ($intent === null || !$this->canReachCart($context) || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }

        $promoCode = strtoupper(trim((string) ($body['promo_code'] ?? '')));
        if ($promoCode !== '') {
            $resolved = $this->promoCodes->resolve($promoCode, 'renewal');
            if (!$resolved['ok']) {
                return $this->renderCart($response, $context, $intent, [$this->promoCodes->errorMessage((string) $resolved['error'])]);
            }
        }
        $intent['promoCode'] = $promoCode;

        $selfWantsRemoval = !empty($body['remove_licence']);
        $selfReason = trim((string) ($body['licence_reason'] ?? ''));
        if ($selfWantsRemoval && !$intent['licenceRemoved'] && $selfReason === '') {
            return $this->renderCart($response, $context, $intent, ['Merci d\'indiquer le motif du retrait de votre licence.']);
        }

        $partnerWantsRemoval = $intent['isCouple'] && !empty($body['partner_remove_licence']);
        $partnerReason = trim((string) ($body['partner_licence_reason'] ?? ''));
        if ($partnerWantsRemoval && !$intent['partnerLicenceRemoved'] && $partnerReason === '') {
            return $this->renderCart($response, $context, $intent, ['Merci d\'indiquer le motif du retrait de la licence de votre conjoint(e).']);
        }

        $isNewSelfRequest = $selfWantsRemoval && !$intent['licenceRemoved'];
        $isNewPartnerRequest = $partnerWantsRemoval && !$intent['partnerLicenceRemoved'];
        if ($isNewSelfRequest || $isNewPartnerRequest) {
            $this->renewals->createLicenceWaiverRequest(
                (int) $context['bjUser']['user_id'],
                (string) $context['bjUser']['email'],
                trim($context['bjUser']['firstname'] . ' ' . $context['bjUser']['lastname']),
                $context['currentLabel'],
                $intent['subscriptionType'],
                $intent['isCouple'],
                $intent['competitor'],
                $intent['lessons'],
                '',
                $context['season']->startYear,
                $isNewSelfRequest ? true : $intent['licenceRemoved'],
                $isNewSelfRequest ? mb_substr($selfReason, 0, 500) : $intent['licenceRemovalReason'],
                $isNewPartnerRequest ? true : $intent['partnerLicenceRemoved'],
                $isNewPartnerRequest ? mb_substr($partnerReason, 0, 500) : $intent['partnerLicenceRemovalReason'],
            );
            unset($_SESSION['renewal_intent']);
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }

        if (!$selfWantsRemoval) {
            $intent['licenceRemoved'] = false;
            $intent['licenceRemovalReason'] = '';
        }
        if ($intent['isCouple'] && !$partnerWantsRemoval) {
            $intent['partnerLicenceRemoved'] = false;
            $intent['partnerLicenceRemovalReason'] = '';
        }

        $subscription = $this->pricing->subscription($intent['subscriptionType'], new Season((int) $intent['seasonStartYear']));
        if ($subscription['audience'] !== 'jeune' && empty($intent['lateSettlement'])) {
            $intent['lessons'] = min((int) !empty($body['lessons_1']), 1)
                + ($intent['isCouple'] ? (int) !empty($body['lessons_2']) : 0);
        }

        $_SESSION['renewal_intent'] = $intent;

        return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement/paiement');
    }

    public function startCheckout(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        $intent = $this->intent($context);
        $body = (array) $request->getParsedBody();
        if ($intent === null || !$this->canReachCart($context) || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }
        if ($this->isMinor($context['bjUser']) && $this->renewals->attestationFor($context['season']->startYear, (int) $context['bjUser']['user_id']) === null) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement/sante');
        }

        // Re-resolve defensively: the stored code may have expired or hit its
        // use limit since it was applied in updateOptions() — block rather
        // than silently drop the discount or let a stale code through.
        $promoCode = (string) ($intent['promoCode'] ?? '');
        $promoResolved = $this->promoCodes->resolve($promoCode, 'renewal');
        if ($promoCode !== '' && !$promoResolved['ok']) {
            return $this->renderCart($response, $context, $intent, [
                $this->promoCodes->errorMessage((string) $promoResolved['error']) . ' Merci de retirer le code promo pour continuer.',
            ]);
        }

        $quote = $this->quoteFor($intent);
        $discountLine = null;
        foreach ($quote->lines as $line) {
            if ($line->type === 'discount') {
                $discountLine = $line;
                break;
            }
        }
        $user = $context['bjUser'];
        $order = $this->orders->create(
            'renewal',
            null,
            (int) $user['user_id'],
            (string) $user['email'],
            $quote->total(),
            array_map(fn ($l) => ['type' => $l->type, 'label' => $l->label, 'amount' => $l->amount], $quote->lines),
            $intent,
            promoCodeId: $promoResolved['promo']['id'] ?? null,
            discountAmount: $discountLine !== null ? -$discountLine->amount : 0.0,
        );

        $uri = $request->getUri();
        $returnUrl = $uri->getScheme() . '://' . $uri->getAuthority() . '/paiement/retour/' . $order['checkout_reference'];
        try {
            $checkout = $this->sumup->createCheckout(
                $order['checkout_reference'],
                (float) $order['amount'],
                'Renouvellement Bad & Squash — saison ' . $context['season']->label(),
                $returnUrl,
            );
        } catch (\RuntimeException $e) {
            return $this->renderCart($response, $context, $intent, [$e->getMessage()]);
        }

        $this->orders->update((int) $order['id'], ['checkout_id' => $checkout['checkout_id']]);
        unset($_SESSION['renewal_intent'], $_SESSION['renewal_choice']);

        return $response->withStatus(302)->withHeader('Location', $checkout['url']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Shared request context. 'redirect' is a renewal_status state when the
     * member cannot renew right now (already renewed / pending request).
     */
    private function context(Request $request): array
    {
        $sessionUser = $request->getAttribute('user') ?? \App\Service\Auth\AuthService::currentUser();
        $bjUser = $this->bj->get('users/' . $sessionUser['bj_user_id'])['user'];
        $now = new DateTimeImmutable();
        $target = $this->renewals->renewalTarget($now, (string) $bjUser['subscription_date_end']);
        $season = $target['season'];
        $lateSettlement = $target['late_settlement'];
        $choiceAvailable = $target['choice_available'];
        $nextPublished = $target['next_published'];

        // State-based only here — the pending/approved change-request lookup
        // (below) needs $season *after* the couple/choice advancements that
        // follow, since a request submitted post-advancement is saved under
        // the advanced season year. Looking it up against the pre-advancement
        // year here would silently miss it (see the choice-based advancement
        // block's comment).
        $redirect = null;
        if ($target['state'] === 'not_yet_open') {
            $redirect = 'not_yet_open';
        } elseif ($target['state'] === 'done') {
            $redirect = 'done';
        }

        $subscriptionName = array_search((int) $bjUser['subscription_id'], $this->subscriptions->map(), true) ?: '';
        $known = $this->renewals->knownFormula((int) $bjUser['user_id']);
        $fromBj = $this->renewals->resolveSubscriptionFromBjName($subscriptionName, $season);
        $current = $this->renewals->resolveCurrentFormula($known, $fromBj);
        $couple = $this->renewals->resolveCoupleStatus($bjUser, $known, $fromBj);

        // Pack été excludes couples — there's no couple-sized equivalent of the flat
        // forfeit, and charging full price to stay on an almost-finished season
        // makes no sense either. Instead: skip straight to next season at full
        // price once its price list is published, or tell the member to wait.
        if ($lateSettlement && $couple['isCouple']) {
            $lateSettlement = false;
            $choiceAvailable = false;
            if ($nextPublished) {
                $season = $season->next();
            } elseif ($redirect === null) {
                $redirect = 'couple_awaiting_next_season';
            }
        }

        // A choice picked on the 'choice' screen persists across the wizard's several
        // page loads via session, keyed to the season it was offered for so it can't
        // leak into a future renewal year. Picking 'next' reconstructs exactly the
        // natural covered(current) && nextPublished && !covered(next) target — no
        // other code path below needs to know a choice was ever involved.
        $queryChoice = (string) ($request->getQueryParams()['choice'] ?? '');
        if ($choiceAvailable && in_array($queryChoice, ['ete', 'next'], true)) {
            $_SESSION['renewal_choice'] = ['seasonStartYear' => $season->startYear, 'choice' => $queryChoice];
        }
        $picked = $_SESSION['renewal_choice'] ?? null;
        if ($picked !== null && (int) $picked['seasonStartYear'] === $season->startYear) {
            if ($picked['choice'] === 'next') {
                $season = $season->next();
                $lateSettlement = false;
            }
            $choiceAvailable = false;
        }

        // Looked up here, after both the couple and choice season advancements
        // above, so a request submitted for an advanced season (e.g. a member
        // who picked "next season") is found under the season it was actually
        // saved under — see the docblock note near $redirect's initial value.
        $changeRequest = $this->renewals->pendingChangeRequest((int) $bjUser['user_id'], $season->startYear);
        if ($redirect === null && $changeRequest !== null && $changeRequest['status'] === 'pending') {
            $redirect = 'change_pending';
        }

        $residence = $this->pricing->residenceForZip((string) $bjUser['postalcode']);

        // Grandfather existing Hors-commune Midi subscribers silently — the
        // renewal flow has no admin-review step to grant the same
        // per-application exception AdminApplicationController offers.
        $midiResidencyOverride = $current !== null
            && $current['subscriptionType'] === 'midi'
            && $residence === PricingService::RESIDENCE_HORS_COMMUNE;

        // An approved change request overrides everything: the member pays
        // the subscription the admin approved.
        if ($changeRequest !== null && $changeRequest['status'] === 'approved') {
            $_SESSION['renewal_intent'] ??= [
                'subscriptionType'            => $changeRequest['subscription_type'],
                'isCouple'                    => (bool) $changeRequest['is_couple'],
                'competitor'                  => (bool) $changeRequest['competitor'],
                'partnerCompetitor'           => false,
                'lessons'                     => $lateSettlement ? 0 : (int) $changeRequest['lessons'],
                'partnerBjUserId'             => (int) ($known['partner_bj_user_id'] ?? 0),
                'seasonStartYear'             => $season->startYear,
                'residence'                   => $residence,
                'licenceRemoved'              => (bool) $changeRequest['licence_removed'],
                'licenceRemovalReason'        => $changeRequest['licence_removal_reason'],
                'partnerLicenceRemoved'       => (bool) $changeRequest['partner_licence_removed'],
                'partnerLicenceRemovalReason' => $changeRequest['partner_licence_removal_reason'],
                'midiResidencyOverride'       => $midiResidencyOverride,
                'changeRequestId'             => (int) $changeRequest['id'],
                'lateSettlement'              => $lateSettlement,
                'promoCode'                   => '',
            ];
            $redirect = 'change_approved';
        }

        $isJeune = ($bjUser['birthday'] ?? '') !== ''
            && $this->pricing->isJeune(new DateTimeImmutable($bjUser['birthday']), $season);
        $subscriptions = array_filter(
            $this->pricing->subscriptionsFor($residence, $season, $midiResidencyOverride),
            fn (array $s) => $isJeune ? $s['audience'] === 'jeune' : $s['audience'] !== 'jeune'
        );

        return [
            'bjUser'                 => $bjUser,
            'season'                 => $season,
            'residence'              => $residence,
            'subscriptions'          => $subscriptions,
            'known'                  => $known,
            'currentSubscriptionType' => $current['subscriptionType'] ?? null,
            'currentIsCouple'        => $couple['isCouple'],
            'currentPartnerBjUserId' => $couple['partnerBjUserId'],
            'currentIsCompetitor'    => $current['isCompetitor'] ?? false,
            'currentLabel'           => $subscriptionName,
            'changeRequest'          => $changeRequest,
            'redirect'               => $redirect,
            'lateSettlement'         => $lateSettlement,
            'choiceAvailable'        => $choiceAvailable,
            'midiResidencyOverride'  => $midiResidencyOverride,
        ];
    }

    private function isMinor(array $bjUser): bool
    {
        return ($bjUser['birthday'] ?? '') !== ''
            && $this->pricing->isMinor(new DateTimeImmutable($bjUser['birthday']), new DateTimeImmutable());
    }

    /** @return array{key:string,label:string,state:string}[] */
    private function renewalSteps(bool $isMinor, bool $isChangeRequest, string $current): array
    {
        $steps = [['key' => 'formule', 'label' => 'Formule']];
        if ($isMinor) {
            $steps[] = ['key' => 'sante', 'label' => 'Santé'];
            $steps[] = ['key' => 'guardian', 'label' => 'Représentant légal'];
        }
        if ($isChangeRequest) {
            $steps[] = ['key' => 'validation', 'label' => 'Validation du club'];
        }
        $steps[] = ['key' => 'paiement', 'label' => 'Paiement'];

        $seenCurrent = false;
        foreach ($steps as &$step) {
            if ($step['key'] === $current) {
                $step['state'] = 'current';
                $seenCurrent = true;
            } else {
                $step['state'] = $seenCurrent ? 'upcoming' : 'done';
            }
        }
        return $steps;
    }

    /**
     * True for a few seconds right after a renewal payment for this exact
     * season, if BJ's own state hasn't caught up to reflect it yet (renewalTarget()
     * is BJ-date-only and can lag a beat behind a just-completed write — see
     * RenewalService's class docblock). Bounded to a handful of attempts within
     * a short window so a genuine problem (not just staleness) still falls
     * through to the normal page rather than looping forever.
     */
    private function justPaidForCurrentSeason(int $seasonStartYear): bool
    {
        $marker = $_SESSION['renewal_just_paid'] ?? null;
        if ($marker === null || $marker['seasonStartYear'] !== $seasonStartYear || time() > $marker['until'] || $marker['attempts'] >= 5) {
            unset($_SESSION['renewal_just_paid']);
            return false;
        }
        $_SESSION['renewal_just_paid']['attempts']++;
        return true;
    }

    private function intent(array $context): ?array
    {
        $intent = $_SESSION['renewal_intent'] ?? null;
        if ($intent === null || (int) $intent['seasonStartYear'] !== $context['season']->startYear) {
            return null;
        }
        return $intent;
    }

    /**
     * Guards the cart/checkout actions against a stale $_SESSION['renewal_intent']
     * (e.g. saved while behind on the season with Pack été pricing, then the
     * member's BJ subscription got covered by some other means before they
     * paid) — intent()'s season-year check alone doesn't catch this, since a
     * member who becomes covered can land back on the *same* season number
     * (e.g. 'not_yet_open'). 'change_approved' is the one redirect state that
     * legitimately still leads here — it's the admin-approved formula the
     * member is meant to pay for now (see renewal_status.php's "Procéder au
     * paiement" link).
     */
    private function canReachCart(array $context): bool
    {
        return $context['redirect'] === null || $context['redirect'] === 'change_approved';
    }

    private function quoteFor(array $intent): Quote
    {
        $people = $intent['isCouple']
            ? [
                ['competitor' => (bool) $intent['competitor'], 'licenceRemoved' => (bool) $intent['licenceRemoved']],
                ['competitor' => (bool) $intent['partnerCompetitor'], 'licenceRemoved' => (bool) $intent['partnerLicenceRemoved']],
            ]
            : [['competitor' => (bool) $intent['competitor'], 'licenceRemoved' => (bool) $intent['licenceRemoved']]];

        // Never throws on a stale/invalid stored code — this also feeds plain
        // cart display, e.g. right after a failed updateOptions().
        $promo = $this->promoCodes->resolve((string) ($intent['promoCode'] ?? ''), 'renewal')['promo'];

        return $this->pricing->quote(
            $intent['subscriptionType'],
            $intent['residence'],
            premiere: false,
            season: new Season((int) $intent['seasonStartYear']),
            joinDate: new DateTimeImmutable(), // prorated unless summerPack (July/Aug) overrides below
            isCouple: (bool) $intent['isCouple'],
            people: $people,
            lessonsCount: (int) $intent['lessons'],
            midiResidencyOverride: (bool) ($intent['midiResidencyOverride'] ?? false),
            summerPack: !empty($intent['lateSettlement']),
            promo: $promo,
        );
    }

    private function findBjUserByEmail(string $email): ?array
    {
        $data = $this->bj->get('users', ['search' => $email, 'limit' => 50]);
        foreach ($data['users'] ?? [] as $user) {
            if (mb_strtolower($user['email'] ?? '') === $email || mb_strtolower($user['email2'] ?? '') === $email) {
                return $user;
            }
        }
        return null;
    }

    private function renderForm(Response $response, array $context, array $old, array $errors): Response
    {
        return $this->renderer->render($response, 'pages/renewal_form.php', [
            'title'   => 'Renouvellement',
            'csrf'    => Csrf::token(),
            'context' => $context,
            'steps'   => $this->renewalSteps($this->isMinor($context['bjUser']), false, 'formule'),
            'old'     => $old,
            'errors'  => $errors,
        ]);
    }

    private function renderCart(Response $response, array $context, array $intent, array $errors): Response
    {
        return $this->renderer->render($response, 'pages/renewal_cart.php', [
            'title'        => 'Paiement du renouvellement',
            'csrf'         => Csrf::token(),
            'season'       => $context['season'],
            'intent'       => $intent,
            'subscription' => $this->pricing->subscription($intent['subscriptionType'], $context['season']),
            'quote'        => $this->quoteFor($intent),
            'steps'        => $this->renewalSteps($this->isMinor($context['bjUser']), !empty($intent['changeRequestId']), 'paiement'),
            'errors'       => $errors,
        ]);
    }

    private function renderSante(Response $response, array $context, array $old, array $errors): Response
    {
        $isChangeRequest = !isset($_SESSION['renewal_intent']);
        return $this->renderer->render($response, 'pages/renewal_sante.php', [
            'title'       => 'Santé',
            'csrf'        => Csrf::token(),
            'bjUser'      => $context['bjUser'],
            'attestation' => $this->renewals->attestationFor($context['season']->startYear, (int) $context['bjUser']['user_id']),
            'steps'       => $this->renewalSteps(true, $isChangeRequest, 'sante'),
            'old'         => $old,
            'errors'      => $errors,
        ]);
    }

    private function renderGuardian(Response $response, array $context, array $old, array $errors): Response
    {
        $isChangeRequest = !isset($_SESSION['renewal_intent']);
        return $this->renderer->render($response, 'pages/renewal_guardian.php', [
            'title'   => 'Représentant légal',
            'csrf'    => Csrf::token(),
            'old'     => $old,
            'steps'   => $this->renewalSteps(true, $isChangeRequest, 'guardian'),
            'errors'  => $errors,
        ]);
    }
}
