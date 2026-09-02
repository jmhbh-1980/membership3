<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AuditLogRepository;
use App\Repository\OrderRepository;
use App\Service\AttestationPdfService;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\SubscriptionResolver;
use App\Service\BankDetailsService;
use App\Service\GuardianContact;
use App\Service\Mailer;
use App\Service\PaymentSettlementService;
use App\Service\PricingService;
use App\Service\PromoCodeService;
use App\Service\Quote;
use App\Service\ReglementInterieurService;
use App\Service\RenewalService;
use App\Service\Season;
use App\Service\ShoesPolicyImageService;
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
    /** Labels for the licence-choice gate — display-only, not the mid-sentence casing used in fulfillment notes. */
    private const array LICENCE_KIND_LABELS = [
        'pass'     => 'Pass (loisir)',
        'federale' => 'Fédérale (compétition)',
        'ete'      => 'Été (Pack été)',
        'jeune'    => 'Jeune',
    ];

    public function __construct(
        private readonly BalleJauneClient $bj,
        private readonly SubscriptionResolver $subscriptions,
        private readonly PricingService $pricing,
        private readonly PromoCodeService $promoCodes,
        private readonly RenewalService $renewals,
        private readonly OrderRepository $orders,
        private readonly SumUpService $sumup,
        private readonly PaymentSettlementService $settlement,
        private readonly AttestationPdfService $attestationPdf,
        private readonly UploadService $uploads,
        private readonly AuditLogRepository $auditLog,
        private readonly Mailer $mailer,
        private readonly BankDetailsService $bankDetails,
        private readonly ReglementInterieurService $reglement,
        private readonly ShoesPolicyImageService $shoesPolicyImage,
        private readonly PhpRenderer $renderer,
        private readonly Logger $logger,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        // "Précédent" from the Formule step, when Formule was only reached by picking
        // Pack été / next season on the choice screen — forgetting the pick re-opens
        // that fork instead of silently keeping the abandoned choice.
        if (($request->getQueryParams()['reset_choice'] ?? null) && isset($_SESSION['renewal_choice'])) {
            unset($_SESSION['renewal_choice']);
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }

        $context = $this->context($request);

        $pending = $context['pendingPromoOrder'] ?? $context['pendingStudentOrder'];
        if ($pending !== null) {
            return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . $pending['checkout_reference']);
        }

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
                ? $this->renewalSteps($this->isMinor($context['bjUser']), true, $context['redirect'] === 'change_pending' ? 'validation' : 'paiement', $context['needsLicenceChoice'])
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

        // An approved licence-kind request seeds the intent (see context())
        // without changing the subscription — nothing to reconsider on
        // Formule, so go straight to the (still mandatory) licence gate.
        $intent = $this->intent($context);
        if ($context['needsLicenceChoice'] && $intent !== null && !empty($intent['changeRequestId'])) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement/licence');
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

        // Formule's own competitor checkboxes are hidden for as long as the licence
        // gate applies (see the template) — so once it's actually been through the
        // gate this visit, the submitted body never carries them and must not be
        // trusted; carry the gate's answer forward from the existing intent instead
        // of silently reverting it to false.
        $existingIntent = $_SESSION['renewal_intent'] ?? null;
        $gateAlreadyAnswered = $context['licenceGateApplies']
            && $existingIntent !== null
            && (int) $existingIntent['seasonStartYear'] === $context['season']->startYear;
        $competitor = $gateAlreadyAnswered ? (bool) $existingIntent['competitor'] : !empty($body['competitor']);
        $partnerCompetitor = $gateAlreadyAnswered ? (bool) ($existingIntent['partnerCompetitor'] ?? false) : !empty($body['partner_competitor']);
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
                // Not $context['needsLicenceChoice']: the change request just created
                // above (whatever its kind) will itself settle the licence question
                // once resolved — showing a 'Licence' step here would be stale the
                // instant this page loads.
                'steps'   => $this->renewalSteps(false, true, 'validation'),
            ]);
        }

        // An already-approved licence-waiver request (context()'s redirect check above
        // already ruled out a still-pending one) carries forward here — otherwise
        // resubmitting this formula step would silently clobber it back to false.
        // Not consulted when $gateAlreadyAnswered — the intent itself (below) is
        // the fresher, authoritative source for a self-service gate resolution,
        // which this never sees (pendingChangeRequest() only knows about
        // admin-approved requests).
        $approvedLicenceWaiver = $gateAlreadyAnswered
            ? null
            : $this->renewals->pendingChangeRequest((int) $context['bjUser']['user_id'], $context['season']->startYear);

        $_SESSION['renewal_intent'] = [
            'subscriptionType'            => $subscriptionKey,
            'isCouple'                    => $isCouple,
            'competitor'                  => $competitor,
            'partnerCompetitor'           => $partnerCompetitor,
            'lessons'                     => $lessons,
            'partnerBjUserId'             => $partnerBjUserId,
            'seasonStartYear'             => $context['season']->startYear,
            'residence'                   => $context['residence'],
            'licenceRemoved'              => $gateAlreadyAnswered ? (bool) $existingIntent['licenceRemoved'] : (bool) ($approvedLicenceWaiver['licence_removed'] ?? false),
            'licenceRemovalReason'        => $gateAlreadyAnswered ? (string) $existingIntent['licenceRemovalReason'] : ($approvedLicenceWaiver['licence_removal_reason'] ?? ''),
            'partnerLicenceRemoved'       => $gateAlreadyAnswered ? (bool) $existingIntent['partnerLicenceRemoved'] : (bool) ($approvedLicenceWaiver['partner_licence_removed'] ?? false),
            'partnerLicenceRemovalReason' => $gateAlreadyAnswered ? (string) $existingIntent['partnerLicenceRemovalReason'] : ($approvedLicenceWaiver['partner_licence_removal_reason'] ?? ''),
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
                    $this->auditLog->log((string) $bjUser['email'], 'renewal_attestation.signed', 'bj_user', (string) $bjUserId, [
                        'season' => $seasonStartYear, 'outcome' => 'all_negative', 'guardian_fullname' => $guardian,
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
                    $this->auditLog->log((string) $bjUser['email'], 'renewal_attestation.signed', 'bj_user', (string) $bjUserId, [
                        'season' => $seasonStartYear, 'outcome' => 'certificate',
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
        $pending = $context['pendingPromoOrder'] ?? $context['pendingStudentOrder'];
        if ($pending !== null) {
            return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . $pending['checkout_reference']);
        }
        $intent = $this->intent($context);
        if ($intent === null || !$this->canReachCart($context)) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }
        if ($this->isMinor($context['bjUser']) && $this->renewals->attestationFor($context['season']->startYear, (int) $context['bjUser']['user_id']) === null) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement/sante');
        }
        if ($context['needsLicenceChoice']) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement/licence');
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
        $pending = $context['pendingPromoOrder'] ?? $context['pendingStudentOrder'];
        if ($pending !== null) {
            return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . $pending['checkout_reference']);
        }
        $intent = $this->intent($context);
        $body = (array) $request->getParsedBody();
        if ($intent === null || !$this->canReachCart($context) || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }
        if ($context['needsLicenceChoice']) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement/licence');
        }

        // A student certificate is annual — a fresh row per season, keyed like
        // renewal_attestations. Uploading here (rather than a dedicated wizard
        // step) mirrors the promo-code field it's mutually exclusive with:
        // both are checkout-time options on this same Cart step.
        $seasonStartYear = (int) $intent['seasonStartYear'];
        $bjUserId = (int) $context['bjUser']['user_id'];
        $existingCert = $intent['isCouple'] ? null : $this->renewals->studentCertificateFor($seasonStartYear, $bjUserId);
        $wantsStudentUpload = !$intent['isCouple']
            && (!empty($body['student_discount']) || ($existingCert !== null && $existingCert['status'] === 'refused'));
        if ($wantsStudentUpload) {
            $file = ($request->getUploadedFiles())['student_certificate'] ?? null;
            if ($file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                try {
                    $stored = $this->uploads->storeRenewalCertificate($file, $seasonStartYear, $bjUserId, 'student_certificate');
                    $this->renewals->saveStudentCertificateRequest($seasonStartYear, $bjUserId, $stored);
                    $existingCert = $this->renewals->studentCertificateFor($seasonStartYear, $bjUserId);
                } catch (\RuntimeException $e) {
                    return $this->renderCart($response, $context, $intent, [$e->getMessage()]);
                }
            } elseif ($existingCert === null) {
                return $this->renderCart($response, $context, $intent, ['Merci de joindre votre certificat de scolarité.']);
            }
        }
        // Once active for the season there's no un-checking it — same as the
        // medical-certificate/attestation flow, which has no withdrawal path
        // either. Only a refused row can be re-uploaded (handled above).
        $studentActive = $existingCert !== null && $existingCert['status'] !== 'refused';

        $promoCode = $studentActive ? '' : strtoupper(trim((string) ($body['promo_code'] ?? '')));
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

    // ── Licence choice (mandatory once BJ's flag signals it's unresolved) ────

    public function showLicenceChoice(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        $intent = $this->intent($context);
        // "Précédent" from Paiement, when the gate was already settled this visit —
        // read the stale needsLicenceChoice=false *before* clearing the marker, so
        // reopening is deliberate (a bare revisit of this URL still redirects on).
        $reopening = ($request->getQueryParams()['reset'] ?? null) !== null && !$context['needsLicenceChoice'];
        if ($reopening) {
            unset($_SESSION['renewal_licence_choice']);
        }
        if ($intent === null || !$this->canReachCart($context) || (!$context['needsLicenceChoice'] && !$reopening)) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }
        return $this->renderLicenceChoice($response, $context, $intent, $this->licenceChoiceDefaults($context, $intent, $reopening), []);
    }

    public function submitLicenceChoice(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        $intent = $this->intent($context);
        $body = (array) $request->getParsedBody();
        if ($intent === null || !$this->canReachCart($context) || !$context['needsLicenceChoice'] || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }

        $offered = $this->offeredLicenceKinds($context['lateSettlement'], $context['isJeune']);
        $valid = [...$offered, 'waive'];

        $selfChoice = (string) ($body['self_choice'] ?? '');
        $selfReason = trim((string) ($body['self_licence_reason'] ?? ''));
        $partnerChoice = $intent['isCouple'] ? (string) ($body['partner_choice'] ?? '') : '';
        $partnerReason = trim((string) ($body['partner_licence_reason'] ?? ''));
        $old = ['self_choice' => $selfChoice, 'self_licence_reason' => $selfReason, 'partner_choice' => $partnerChoice, 'partner_licence_reason' => $partnerReason];

        if (!in_array($selfChoice, $valid, true)) {
            return $this->renderLicenceChoice($response, $context, $intent, $old, ['Merci de choisir une option pour votre licence.']);
        }
        $selfWaiving = $selfChoice === 'waive';
        // A reason is only mandatory for a genuinely NEW waiver — re-confirming
        // (or editing the reason on) an already-waived licence doesn't re-ask.
        $isNewSelfWaiver = $selfWaiving && !$intent['licenceRemoved'];
        if ($isNewSelfWaiver && $selfReason === '') {
            return $this->renderLicenceChoice($response, $context, $intent, $old, ['Merci de préciser le motif du retrait de votre licence.']);
        }

        $partnerWaiving = false;
        $isNewPartnerWaiver = false;
        if ($intent['isCouple']) {
            if (!in_array($partnerChoice, $valid, true)) {
                return $this->renderLicenceChoice($response, $context, $intent, $old, ['Merci de choisir une option pour la licence de votre conjoint(e).']);
            }
            $partnerWaiving = $partnerChoice === 'waive';
            $isNewPartnerWaiver = $partnerWaiving && !$intent['partnerLicenceRemoved'];
            if ($isNewPartnerWaiver && $partnerReason === '') {
                return $this->renderLicenceChoice($response, $context, $intent, $old, ['Merci de préciser le motif du retrait de la licence de votre conjoint(e).']);
            }
        }

        // Final settled state regardless of whether it needs a fresh
        // approval — a real kind (pass/fédérale/été/jeune), or a reverted
        // waiver, never needs one; only a brand-new waiver does (same
        // asymmetry as the cart's own licence checkbox: "reverting an
        // already-effective waiver back to full price never does, since
        // that only ever benefits the club").
        $selfCompetitor = $selfWaiving ? $intent['competitor'] : ($selfChoice === 'federale');
        $partnerCompetitor = ($intent['isCouple'] && !$partnerWaiving) ? ($partnerChoice === 'federale') : ($intent['partnerCompetitor'] ?? false);
        $selfReasonFinal = $selfWaiving ? mb_substr($selfReason, 0, 500) : '';
        $partnerReasonFinal = $partnerWaiving ? mb_substr($partnerReason, 0, 500) : '';

        if ($isNewSelfWaiver || $isNewPartnerWaiver) {
            $user = $context['bjUser'];
            $this->renewals->createLicenceWaiverRequest(
                (int) $user['user_id'],
                (string) $user['email'],
                trim($user['firstname'] . ' ' . $user['lastname']),
                $context['currentLabel'],
                $intent['subscriptionType'],
                $intent['isCouple'],
                $selfCompetitor,
                $intent['lessons'],
                '',
                $context['season']->startYear,
                $selfWaiving,
                $selfReasonFinal,
                $partnerWaiving,
                $partnerReasonFinal,
            );
            unset($_SESSION['renewal_intent']);
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }

        // No new waiver — self-service, settle directly onto the intent (this
        // is also the only place competitor status ever gets set, since
        // Formule's own checkbox is hidden whenever this gate applies).
        $intent['competitor'] = $selfCompetitor;
        $intent['licenceRemoved'] = $selfWaiving;
        $intent['licenceRemovalReason'] = $selfReasonFinal;
        if ($intent['isCouple']) {
            $intent['partnerCompetitor'] = $partnerCompetitor;
            $intent['partnerLicenceRemoved'] = $partnerWaiving;
            $intent['partnerLicenceRemovalReason'] = $partnerReasonFinal;
        }
        $_SESSION['renewal_intent'] = $intent;
        $_SESSION['renewal_licence_choice'] = ['seasonStartYear' => $context['season']->startYear];
        return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement/paiement');
    }

    /**
     * Pre-fill for a fresh (first) visit to the gate: blank unless either the
     * intent was seeded from an approved licence-kind request (see context()),
     * or this is a reopened gate (Précédent from Paiement, reconsidering an
     * already self-service-settled answer) — in which case it defaults to
     * what was approved/chosen. Partner's competitor status was never
     * captured on a change request (no partner_competitor column) — a waived
     * partner pre-fills cleanly, a kept one is left unselected rather than
     * guessing pass vs fédérale (not an issue on reopen, where it's always
     * known from the intent itself).
     *
     * @return array{self_choice:string, self_licence_reason:string, partner_choice:string, partner_licence_reason:string}
     */
    private function licenceChoiceDefaults(array $context, array $intent, bool $reopening = false): array
    {
        if (empty($intent['changeRequestId']) && !$reopening) {
            return ['self_choice' => '', 'self_licence_reason' => '', 'partner_choice' => '', 'partner_licence_reason' => ''];
        }
        $offered = $this->offeredLicenceKinds($context['lateSettlement'], $context['isJeune']);
        $kindFor = fn (bool $removed, bool $competitor): string => $removed ? 'waive' : (count($offered) === 1 ? $offered[0] : ($competitor ? 'federale' : 'pass'));
        return [
            'self_choice'            => $kindFor(!empty($intent['licenceRemoved']), !empty($intent['competitor'])),
            'self_licence_reason'    => (string) ($intent['licenceRemovalReason'] ?? ''),
            'partner_choice'         => $intent['isCouple']
                ? (!empty($intent['partnerLicenceRemoved'])
                    ? 'waive'
                    : ($reopening ? $kindFor(false, !empty($intent['partnerCompetitor'])) : (count($offered) === 1 ? $offered[0] : '')))
                : '',
            'partner_licence_reason' => (string) ($intent['partnerLicenceRemovalReason'] ?? ''),
        ];
    }

    private function renderLicenceChoice(Response $response, array $context, array $intent, array $old, array $errors): Response
    {
        $offered = $this->offeredLicenceKinds($context['lateSettlement'], $context['isJeune']);
        $steps = $this->renewalSteps($this->isMinor($context['bjUser']), false, 'licence', true);
        return $this->renderer->render($response, 'pages/renewal_licence_choice.php', [
            'title'    => 'Licence',
            'csrf'     => Csrf::token(),
            'season'   => $context['season'],
            'isCouple' => $intent['isCouple'],
            'kinds'    => array_intersect_key(self::LICENCE_KIND_LABELS, array_flip($offered)),
            'old'      => $old,
            'steps'    => $steps,
            'backUrl'  => $this->previousStepUrl($steps, 'licence'),
            'errors'   => $errors,
        ]);
    }

    public function startCheckout(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        $pendingApproval = $context['pendingPromoOrder'] ?? $context['pendingStudentOrder'];
        if ($pendingApproval !== null) {
            return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . $pendingApproval['checkout_reference']);
        }
        $intent = $this->intent($context);
        $body = (array) $request->getParsedBody();
        if ($intent === null || !$this->canReachCart($context) || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement');
        }
        if ($this->isMinor($context['bjUser']) && $this->renewals->attestationFor($context['season']->startYear, (int) $context['bjUser']['user_id']) === null) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement/sante');
        }
        if ($context['needsLicenceChoice']) {
            return $response->withStatus(302)->withHeader('Location', '/espace/renouvellement/licence');
        }
        $consentErrors = [];
        if (empty($body['reglement_accepted'])) {
            $consentErrors[] = 'Merci d\'accepter le règlement intérieur pour continuer.';
        }
        if (empty($body['shoes_policy_accepted'])) {
            $consentErrors[] = 'Merci de confirmer avoir pris connaissance des règles chaussures pour continuer.';
        }
        if ($consentErrors !== []) {
            return $this->renderCart($response, $context, $intent, $consentErrors);
        }
        $this->auditLog->log((string) $context['bjUser']['email'], 'reglement_interieur.accepted', 'bj_user', (string) $context['bjUser']['user_id'], ['kind' => 'renewal']);
        $this->auditLog->log((string) $context['bjUser']['email'], 'shoes_policy.accepted', 'bj_user', (string) $context['bjUser']['user_id'], ['kind' => 'renewal']);

        // Same season+bjUser lookup as renderCart()/updateOptions() — the
        // uploaded row (if any) is the source of truth for whether the
        // student discount is active for this checkout, not anything stored
        // in $intent.
        $seasonStartYear = (int) $intent['seasonStartYear'];
        $bjUserId = (int) $context['bjUser']['user_id'];
        $studentCertificate = $intent['isCouple'] ? null : $this->renewals->studentCertificateFor($seasonStartYear, $bjUserId);
        $studentActive = $studentCertificate !== null && $studentCertificate['status'] !== 'refused';

        // Re-resolve defensively: the stored code may have expired or hit its
        // use limit since it was applied in updateOptions() — block rather
        // than silently drop the discount or let a stale code through.
        $promoCode = $studentActive ? '' : (string) ($intent['promoCode'] ?? '');
        $promoResolved = $this->promoCodes->resolve($promoCode, 'renewal');
        if ($promoCode !== '' && !$promoResolved['ok']) {
            return $this->renderCart($response, $context, $intent, [
                $this->promoCodes->errorMessage((string) $promoResolved['error']) . ' Merci de retirer le code promo pour continuer.',
            ]);
        }

        // A promo code or a student-discount request each need admin approval
        // before any payment link is issued — see AdminPromoCodeController /
        // AdminOpsController::decideStudentDiscount(). Unlike join
        // (applications.promo_code is durable and gets cleared on refusal),
        // the renewal intent only lives in session, so a previously-refused
        // promo code has to be detected here and dropped rather than
        // resubmitted into a second approval request.
        $requiresPromoApproval = $promoCode !== '' && $promoResolved['ok'];
        if ($requiresPromoApproval && $this->orders->hasRefusedPromoUsage((int) $context['bjUser']['user_id'], (int) $promoResolved['promo']['id'])) {
            $intent['promoCode'] = '';
            $_SESSION['renewal_intent'] = $intent;
            return $this->renderCart($response, $context, $intent, [
                'Ce code promo n\'a pas été validé par le club — vous pouvez continuer sans.',
            ]);
        }
        $requiresApproval = $requiresPromoApproval || $studentActive;

        // Bank transfer is unavailable while a promo code needs admin approval
        // first — combining both admin-gated flows on one order isn't worth
        // the complexity; the cart template already hides the option in that
        // case, this is the server-side backstop.
        $paymentMethod = !$requiresApproval && ($body['payment_method'] ?? 'online') === 'bank_transfer' ? 'bank_transfer' : 'online';

        // Computed here (rather than where it's used, right before order
        // creation) so resumeIfOpen() below can compare a stale order's
        // stored amount against what the cart actually totals to *now*.
        $quote = $this->quoteFor($intent, $studentActive);

        if ($paymentMethod === 'bank_transfer') {
            // Resume our own still-open wait rather than duplicating it.
            $pendingTransfer = $this->orders->findAwaitingBankTransferByBjUser($bjUserId, 'renewal');
            if ($pendingTransfer !== null) {
                return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . $pendingTransfer['checkout_reference']);
            }
            // The member may be switching away from an abandoned online
            // attempt — honor it if it actually succeeded in the background,
            // otherwise close it out rather than silently resuming its SumUp
            // checkout and overriding the choice they just made.
            $switchUrl = $this->settlement->abandonForSwitch($this->orders->findOpenOrderByBjUser($bjUserId, 'renewal'));
            if ($switchUrl !== null) {
                return $response->withStatus(302)->withHeader('Location', $switchUrl);
            }
        } else {
            // Mirror image: switching away from an abandoned bank-transfer
            // wait to pay online instead — cancel it so it doesn't linger
            // unresolved once the member has moved on.
            $pendingTransfer = $this->orders->findAwaitingBankTransferByBjUser($bjUserId, 'renewal');
            if ($pendingTransfer !== null) {
                $this->orders->transition((int) $pendingTransfer['id'], 'awaiting_bank_transfer', 'canceled');
            }

            // Don't spawn a duplicate order/checkout if the member already has one
            // open (abandoned checkout, or a page error after a charge that actually
            // went through) — see PaymentSettlementService::resumeIfOpen().
            $resumeUrl = $this->settlement->resumeIfOpen($this->orders->findOpenOrderByBjUser($bjUserId, 'renewal'), $quote->total());
            if ($resumeUrl !== null) {
                return $response->withStatus(302)->withHeader('Location', $resumeUrl);
            }
        }

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
            array_map(fn ($l) => [
                'type' => $l->type, 'label' => $l->label, 'amount' => $l->amount,
                'baseAmount' => $l->baseAmount, 'personIndex' => $l->personIndex,
            ], $quote->lines),
            $intent,
            promoCodeId: $promoResolved['promo']['id'] ?? null,
            discountAmount: $discountLine !== null ? -$discountLine->amount : 0.0,
            paymentMethod: $paymentMethod,
            studentDiscount: $studentActive,
        );

        if ($requiresApproval) {
            $this->orders->transition((int) $order['id'], 'pending', $studentActive ? 'awaiting_student_approval' : 'awaiting_promo_approval');
            unset($_SESSION['renewal_intent'], $_SESSION['renewal_choice']);
            return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . $order['checkout_reference']);
        }

        if ($paymentMethod === 'bank_transfer') {
            $this->orders->transition((int) $order['id'], 'pending', 'awaiting_bank_transfer');
            unset($_SESSION['renewal_intent'], $_SESSION['renewal_choice']);
            $this->sendBankTransferInstructions($order, $context['season']->label());
            return $response->withStatus(302)->withHeader('Location', '/paiement/retour/' . $order['checkout_reference']);
        }

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
        // Independent of everything below (season/couple/choice logic never
        // affects whether a promo-code order is awaiting admin approval) —
        // callers check this before rendering the normal cart/formule flow,
        // same idea as the change_pending redirect but pointing at the
        // shared order-status page instead of renewal_status.php.
        $pendingPromoOrder = $this->orders->findAwaitingPromoApprovalByBjUser((int) $bjUser['user_id']);
        $pendingStudentOrder = $this->orders->findAwaitingStudentApprovalByBjUser((int) $bjUser['user_id']);
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
        // Evaluated against the season late settlement is actually about — isJeune()
        // is season-dependent (age at Sept 1), so this can differ from the later,
        // possibly-advanced recomputation near the subscriptions filter below.
        $isJeune = $this->isJeune($bjUser, $season);

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

        // Pack été excludes Jeune subscribers too — their pricing/licence structure
        // (audience 'jeune') is entirely separate from the adult formulas, and Pack
        // été's flat rate is fixed to Heures Pleines (an adult-only key), so there's
        // no sensible Jeune-priced flat fee to offer. Same resolution as the couple
        // carve-out above: skip straight to next season at full price once its
        // price list is published, or tell the member to wait.
        if ($lateSettlement && $isJeune) {
            $lateSettlement = false;
            $choiceAvailable = false;
            if ($nextPublished) {
                $season = $season->next();
            } elseif ($redirect === null) {
                $redirect = 'jeune_awaiting_next_season';
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
        $reachedViaChoice = $picked !== null && (int) $picked['seasonStartYear'] === $season->startYear;
        if ($reachedViaChoice) {
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

        // An approved change request settles what the member pays — except a
        // licence-kind approval, which only pre-fills the (still-mandatory)
        // licence gate rather than bypassing it: flag=1 means the licence
        // question stays re-askable every visit, so an old waiver shouldn't
        // silently skip that, only offer it back as the starting choice.
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
            if ($changeRequest['kind'] !== 'licence') {
                $redirect = 'change_approved';
            }
        }

        // Recomputed against the final (possibly season-advanced) $season — see the
        // earlier evaluation's comment for why this can differ from that one.
        $isJeune = $this->isJeune($bjUser, $season);
        $subscriptions = array_filter(
            $this->pricing->subscriptionsFor($residence, $season, $midiResidencyOverride),
            fn (array $s) => $isJeune ? $s['audience'] === 'jeune' : $s['audience'] !== 'jeune'
        );
        if ($lateSettlement) {
            // Pack été is fixed to Heures Pleines — no formula choice (matches the
            // join wizard's equivalent: ProspectController::submitFormule() forces
            // 'heures-pleines' server-side when summer_pack applies). Filtering the
            // offered set here, rather than just relabeling each option in the
            // template, means Formule never shows the other formulas as selectable
            // in the first place, and submit()'s own validation naturally rejects
            // anything else too.
            $subscriptions = array_intersect_key($subscriptions, ['heures-pleines' => null]);
        }

        // BJ's flag ("licence not registered yet with the federation") is the
        // source of truth for whether the gate applies to this member at all
        // this season — pick a licence kind or waive it — rather than
        // silently defaulting via the cart's easy-to-miss checkbox. A pending
        // request (any kind) already short-circuits above (redirect !== null
        // by this point), and an approved formula/couple change bypasses too
        // (same reasoning) — only an approved *licence*-kind request (or no
        // request at all) reaches here.
        $licenceGateApplies = $redirect === null
            && (int) ($bjUser['flag'] ?? 0) === 1
            && ($changeRequest === null || $changeRequest['kind'] === 'licence');
        // needsLicenceChoice narrows that to "...and not yet resolved this
        // visit" (session-remembered per season, same pattern as the Pack été
        // choice above) — it drives the mandatory gate redirect. Formule's
        // own competitor checkbox, however, must stay keyed to
        // licenceGateApplies alone: once the gate applies, it's the gate's
        // job for the rest of this visit too, not Formule's — otherwise the
        // checkbox reappears (reset to BJ's stale value) the moment the gate
        // is resolved, and resubmitting Formule silently clobbers the
        // member's actual choice back to it.
        $licenceChoiceKept = $_SESSION['renewal_licence_choice'] ?? null;
        $needsLicenceChoice = $licenceGateApplies
            && !($licenceChoiceKept !== null && (int) $licenceChoiceKept['seasonStartYear'] === $season->startYear);

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
            'needsLicenceChoice'     => $needsLicenceChoice,
            'licenceGateApplies'     => $licenceGateApplies,
            'redirect'               => $redirect,
            'lateSettlement'         => $lateSettlement,
            'choiceAvailable'        => $choiceAvailable,
            'reachedViaChoice'       => $reachedViaChoice,
            'midiResidencyOverride'  => $midiResidencyOverride,
            'isJeune'                => $isJeune,
            'pendingPromoOrder'      => $pendingPromoOrder,
            'pendingStudentOrder'    => $pendingStudentOrder,
        ];
    }

    /**
     * Licence kinds offered on the mandatory choice gate, contextual to this
     * renewal: Pack été overrides to the flat-rate 'ete' licence regardless of
     * competitor status (matches Quote's own summerPack override), a Jeune
     * subscription always uses 'jeune' (no pass/fédérale distinction), and
     * otherwise it's a real choice between the two. Order matches
     * FulfillmentService's join-side precedent (summer pack wins over Jeune
     * on the rare occasion both apply).
     *
     * @return string[]
     */
    private function offeredLicenceKinds(bool $lateSettlement, bool $isJeune): array
    {
        if ($lateSettlement) {
            return ['ete'];
        }
        if ($isJeune) {
            return ['jeune'];
        }
        return ['pass', 'federale'];
    }

    private function isMinor(array $bjUser): bool
    {
        return ($bjUser['birthday'] ?? '') !== ''
            && $this->pricing->isMinor(new DateTimeImmutable($bjUser['birthday']), new DateTimeImmutable());
    }

    /** Jeune status is season-dependent (age at season start) — always evaluate
     *  against the specific season in question; never assume it carries over
     *  across a season advancement (see context()'s two call sites). */
    private function isJeune(array $bjUser, Season $season): bool
    {
        return ($bjUser['birthday'] ?? '') !== ''
            && $this->pricing->isJeune(new DateTimeImmutable($bjUser['birthday']), $season);
    }

    /** @return array{key:string,label:string,state:string}[] */
    private function renewalSteps(bool $isMinor, bool $isChangeRequest, string $current, bool $needsLicenceChoice = false): array
    {
        $steps = [['key' => 'formule', 'label' => 'Formule']];
        if ($isMinor) {
            $steps[] = ['key' => 'sante', 'label' => 'Santé'];
            $steps[] = ['key' => 'guardian', 'label' => 'Représentant légal'];
        }
        if ($needsLicenceChoice) {
            $steps[] = ['key' => 'licence', 'label' => 'Licence'];
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

    /** URL for each step key — 'validation' has no page of its own (a status/redirect,
     *  not a form) so it maps to the same URL as 'formule': re-visiting it re-evaluates
     *  context() and lands back on whichever page (status or form) is now current. */
    private const array STEP_URLS = [
        'formule'    => '/espace/renouvellement',
        'sante'      => '/espace/renouvellement/sante',
        'guardian'   => '/espace/renouvellement/representant-legal',
        'licence'    => '/espace/renouvellement/licence',
        'validation' => '/espace/renouvellement',
        'paiement'   => '/espace/renouvellement/paiement',
    ];

    /** The member's profile is the chain's root — every step ultimately leads back to it. */
    private function previousStepUrl(array $steps, string $current): string
    {
        $keys = array_column($steps, 'key');
        $index = array_search($current, $keys, true);
        if ($index === false || $index === 0) {
            return '/espace';
        }
        return self::STEP_URLS[$keys[$index - 1]] ?? '/espace';
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

    private function quoteFor(array $intent, bool $studentDiscount = false): Quote
    {
        $people = $intent['isCouple']
            ? [
                ['competitor' => (bool) $intent['competitor'], 'licenceRemoved' => (bool) $intent['licenceRemoved']],
                ['competitor' => (bool) $intent['partnerCompetitor'], 'licenceRemoved' => (bool) $intent['partnerLicenceRemoved']],
            ]
            : [['competitor' => (bool) $intent['competitor'], 'licenceRemoved' => (bool) $intent['licenceRemoved']]];

        // Never throws on a stale/invalid stored code — this also feeds plain
        // cart display, e.g. right after a failed updateOptions(). Mutually
        // exclusive with the student discount — see startCheckout()/renderCart().
        $promo = $studentDiscount ? null : $this->promoCodes->resolve((string) ($intent['promoCode'] ?? ''), 'renewal')['promo'];

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
            studentDiscount: $studentDiscount,
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
        $steps = $this->renewalSteps($this->isMinor($context['bjUser']), false, 'formule', $context['needsLicenceChoice']);
        // Reached via the Pack été / next-season fork? Back should re-open that
        // choice, not skip past it straight to the profile.
        $backUrl = $context['reachedViaChoice']
            ? '/espace/renouvellement?reset_choice=1'
            : $this->previousStepUrl($steps, 'formule');
        return $this->renderer->render($response, 'pages/renewal_form.php', [
            'title'   => 'Renouvellement',
            'csrf'    => Csrf::token(),
            'context' => $context,
            'steps'   => $steps,
            'backUrl' => $backUrl,
            'old'     => $old,
            'errors'  => $errors,
        ]);
    }

    private function renderCart(Response $response, array $context, array $intent, array $errors): Response
    {
        // needsLicenceChoice is always false by the time the cart renders (it's
        // already resolved) — the session marker is what actually distinguishes
        // "went through the licence gate this visit" (label it "Licence", done)
        // from "an approved formula/couple change" (label it "Validation du
        // club", the gate was never involved).
        $licenceKept = $_SESSION['renewal_licence_choice'] ?? null;
        $licenceStepDone = $licenceKept !== null && (int) $licenceKept['seasonStartYear'] === $context['season']->startYear;
        $steps = $this->renewalSteps($this->isMinor($context['bjUser']), !empty($intent['changeRequestId']) && !$licenceStepDone, 'paiement', $licenceStepDone);
        $backUrl = $this->previousStepUrl($steps, 'paiement');
        if ($licenceStepDone) {
            // The licence page redirects straight past itself once resolved (it's
            // the only way 'licence' can be the step right before this one) — ask
            // it to reopen instead, same as the Pack été choice's own reset link.
            $backUrl .= '?reset=1';
        }
        $studentCertificate = $intent['isCouple']
            ? null
            : $this->renewals->studentCertificateFor($context['season']->startYear, (int) $context['bjUser']['user_id']);
        $studentDiscount = $studentCertificate !== null && $studentCertificate['status'] !== 'refused';

        return $this->renderer->render($response, 'pages/renewal_cart.php', [
            'title'        => 'Paiement du renouvellement',
            'csrf'         => Csrf::token(),
            'season'       => $context['season'],
            'intent'       => $intent,
            'subscription' => $this->pricing->subscription($intent['subscriptionType'], $context['season']),
            'quote'        => $this->quoteFor($intent, $studentDiscount),
            'studentCertificate' => $studentCertificate,
            'steps'        => $steps,
            'backUrl'      => $backUrl,
            'reglementHtml' => $this->reglement->html(),
            'shoesPolicyImageUrl' => $this->shoesPolicyImage->url(),
            'errors'       => $errors,
        ]);
    }

    private function sendBankTransferInstructions(array $order, string $seasonLabel): void
    {
        $bank = $this->bankDetails->current();
        $this->mailer->send(
            (string) $order['email'],
            'Instructions pour votre virement — Bad & Squash',
            '<p>Bonjour,</p>'
            . '<p>Pour finaliser votre renouvellement (saison ' . htmlspecialchars($seasonLabel, ENT_QUOTES) . '), merci d\'effectuer un virement de <strong>' . number_format((float) $order['amount'], 2, ',', ' ') . ' €</strong> aux coordonnées suivantes :</p>'
            . '<p>' . htmlspecialchars($bank['name'], ENT_QUOTES) . '<br>'
            . 'IBAN : ' . htmlspecialchars($bank['iban'], ENT_QUOTES) . '<br>'
            . 'BIC : ' . htmlspecialchars($bank['bic'], ENT_QUOTES) . '</p>'
            . '<p><strong>Référence à indiquer impérativement : ' . htmlspecialchars(OrderRepository::bankTransferReference($order), ENT_QUOTES) . '</strong> (sans cette référence, le club ne peut pas identifier votre virement).</p>'
            . '<p>Votre renouvellement sera finalisé dès que le club aura constaté la réception du virement — comptez quelques jours ouvrés.</p>',
            'bank_transfer_instructions',
        );
    }

    private function renderSante(Response $response, array $context, array $old, array $errors): Response
    {
        $isChangeRequest = !isset($_SESSION['renewal_intent']);
        $steps = $this->renewalSteps(true, $isChangeRequest, 'sante', $context['needsLicenceChoice']);
        return $this->renderer->render($response, 'pages/renewal_sante.php', [
            'title'       => 'Santé',
            'csrf'        => Csrf::token(),
            'bjUser'      => $context['bjUser'],
            'attestation' => $this->renewals->attestationFor($context['season']->startYear, (int) $context['bjUser']['user_id']),
            'steps'       => $steps,
            'backUrl'     => $this->previousStepUrl($steps, 'sante'),
            'old'         => $old,
            'errors'      => $errors,
        ]);
    }

    private function renderGuardian(Response $response, array $context, array $old, array $errors): Response
    {
        $isChangeRequest = !isset($_SESSION['renewal_intent']);
        $steps = $this->renewalSteps(true, $isChangeRequest, 'guardian', $context['needsLicenceChoice']);
        return $this->renderer->render($response, 'pages/renewal_guardian.php', [
            'title'   => 'Représentant légal',
            'csrf'    => Csrf::token(),
            'old'     => $old,
            'steps'   => $steps,
            'backUrl' => $this->previousStepUrl($steps, 'guardian'),
            'errors'  => $errors,
        ]);
    }
}
