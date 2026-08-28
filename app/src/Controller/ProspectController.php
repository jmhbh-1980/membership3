<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ApplicationRepository;
use App\Repository\AuditLogRepository;
use App\Service\AttestationPdfService;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\Mailer;
use App\Service\PricingService;
use App\Service\Quote;
use App\Service\Season;
use App\Service\UploadService;
use App\Support\Csrf;
use App\Support\Logger;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/**
 * Prospect registration wizard (no account needed — the application is
 * reachable through its token, emailed as a resumable link):
 *   1. /inscription                          identity & contact (creates the draft)
 *      /inscription/{token}/informations     same step, editable once the draft exists (e.g. via "Précédent")
 *      /inscription/{token}/formule-saison   Pack été vs. next season, only when both are genuinely open —
 *                                             shared by the first-time ask (from /inscription) and any later
 *                                             reconsideration (from Formule's "Précédent")
 *   2. /inscription/{token}/representant-legal  minors: guardian contact
 *   3. /inscription/{token}/formule          formula, competitor, lessons, couple toggle
 *   4. /inscription/{token}/conjoint         couple: partner identity
 *   5. /inscription/{token}/documents        photo + justificatif de domicile
 *   6. /inscription/{token}/sante            minors: questionnaire + attestation or certificate
 *   7. /inscription/{token}/licence          licence keep/remove choice
 *   8. /inscription/{token}/recapitulatif    quote preview + submit
 *      /inscription/{token}/confirmation     status page after submission
 * Steps 2, 4 and 6 are skipped (auto-redirected past) when they don't apply.
 */
final class ProspectController
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly PricingService $pricing,
        private readonly UploadService $uploads,
        private readonly AttestationPdfService $attestationPdf,
        private readonly Mailer $mailer,
        private readonly BalleJauneClient $bj,
        private readonly AuditLogRepository $auditLog,
        private readonly PhpRenderer $renderer,
        private readonly Logger $logger,
    ) {
    }

    /**
     * True for the July/August window Pack été applies in. Used by submitStart()
     * alongside an adult/Jeune check: adults get the current, almost-over season
     * at the flat Pack été rate; Jeune applicants aren't offered it at all (see
     * submitStart()) since it's fixed to the adult Heures Pleines formula.
     * Every other month targets the current season with the mid-season prorata
     * applying from October, as usual.
     */
    public static function isSummerPackApplication(DateTimeImmutable $today): bool
    {
        $month = (int) $today->format('n');
        return $month === 7 || $month === 8;
    }

    // ── Step 1: identity ─────────────────────────────────────────────────

    public function showStart(Request $request, Response $response): Response
    {
        return $this->renderStart($response, [], []);
    }

    public function submitStart(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/inscription');
        }

        [$person, $errors] = $this->validateIdentity($body, requireAddress: true);

        if ($errors !== []) {
            return $this->renderStart($response, $body, $errors);
        }

        if ($this->findBjUserByEmail($person['email']) !== null) {
            return $this->renderStart($response, $body, [], existingMember: true);
        }

        $now = new DateTimeImmutable();
        $season = Season::fromDate($now);
        $summerPack = false;
        $needsPackChoice = false;

        if (self::isSummerPackApplication($now)) {
            // Pack été is an adult product (fixed to Heures Pleines — see submitFormule()):
            // a Jeune applicant isn't offered it, only the upcoming season once its price
            // list is published (or told to come back once it is). An adult IS offered
            // Pack été, but gets a choice against the upcoming season too once it's
            // published — otherwise Pack été is the only option, same as before.
            $nextSeason = $season->next();
            $nextPublished = $this->pricing->hasCatalogue($nextSeason);
            $isJeune = $this->pricing->isJeune(new DateTimeImmutable($person['birthdate']), $season);

            if ($isJeune) {
                if (!$nextPublished) {
                    return $this->renderStart($response, $body, [], jeuneNotYetOpen: true);
                }
                $season = $nextSeason;
            } elseif (!$nextPublished) {
                $summerPack = true;
            } else {
                // Both options genuinely open — ask on the shared choice screen
                // (showSummerPackChoice()) once the draft exists below, instead of
                // a modal here; the placeholder season/pack is overwritten the
                // instant it's answered, so its exact value doesn't matter.
                $needsPackChoice = true;
            }
        }

        $app = $this->applications->create($person['email'], $season->startYear, $summerPack);
        $this->applications->update((int) $app['id'], [
            'residence' => $this->pricing->residenceForZip($person['postalcode']),
        ]);
        $this->applications->savePerson((int) $app['id'], 1, $person);

        $next = $needsPackChoice ? 'formule-saison' : ($person['is_minor'] ? 'representant-legal' : 'formule');
        $link = $this->baseUrl($request) . '/inscription/' . $app['token'] . '/' . $next;
        $this->mailer->send(
            $person['email'],
            'Votre demande d\'adhésion — Bad & Squash',
            '<p>Bonjour,</p><p>Votre demande d\'adhésion a bien été commencée. '
            . 'Vous pouvez la reprendre à tout moment via ce lien :</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Reprendre ma demande</a></p>',
            'application_resume',
        );

        return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/' . $next);
    }

    // ── Step 1 (edit): identity, once a draft already exists ────────────

    public function showInformations(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        $people = $this->applications->people((int) $app['id']);
        return $this->renderInformations($response, $app, $people[1], []);
    }

    public function submitInformations(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $this->renderInformations($response, $app, $body, ['Session expirée, merci de réessayer.']);
        }

        [$person, $errors] = $this->validateIdentity($body, requireAddress: true);
        if ($errors !== []) {
            return $this->renderInformations($response, $app, $body, $errors);
        }

        $people = $this->applications->people((int) $app['id']);
        $existing = $people[1];
        if ($person['email'] !== $existing['email'] && $this->findBjUserByEmail($person['email']) !== null) {
            return $this->renderInformations($response, $app, $body, [], existingMember: true);
        }

        $this->applications->savePerson((int) $app['id'], 1, array_merge($existing, $person));

        // The contact email tracks the applicant's own email until the
        // guardian step (if any) has taken it over — don't clobber an
        // already-set guardian contact with the applicant's corrected email.
        $fields = ['residence' => $this->pricing->residenceForZip($person['postalcode'])];
        if (!$person['is_minor'] || $app['email'] === $existing['email']) {
            $fields['email'] = $person['email'];
        }
        $this->applications->update((int) $app['id'], $fields);

        $next = $person['is_minor'] ? 'representant-legal' : 'formule';
        return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/' . $next);
    }

    // ── Step 1b: Pack été vs. next-season fork ───────────────────────────
    //
    // Shared by both the first-time ask and any later reconsideration —
    // one screen, one URL, always the current on-disk truth for this draft.
    // Formerly a modal shown inline on /inscription before the draft even
    // existed: that meant submitStart() baked the answer straight into
    // season_start_year/summer_pack with no later step ever offering it
    // again, so "Précédent" from Formule back to informations was a dead
    // end for anyone who picked wrong. Now the draft is created first
    // (with a throwaway placeholder season/pack — see submitStart()) and
    // always routed here to actually decide it, so revisiting later is
    // just... visiting this URL again.

    public function showSummerPackChoice(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        if (!$this->summerPackChoiceEligible($app)) {
            return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/informations');
        }
        $currentSeason = Season::fromDate(new DateTimeImmutable());
        $steps = $this->wizardSteps($app, 'formule');
        return $this->renderer->render($response, 'pages/join_summer_pack_choice.php', [
            'title'         => 'Choisir la formule',
            'csrf'          => Csrf::token(),
            'app'           => $app,
            'currentSeason' => $currentSeason,
            'nextSeason'    => $currentSeason->next(),
            // The fork's own "précédent" resumes the normal chain — informations,
            // or representant-legal for a minor — since Formule already routes
            // here directly instead of through it.
            'backUrl'       => $this->previousStepUrl($steps, 'formule', $app['token']),
        ]);
    }

    public function submitSummerPackChoice(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null) || !$this->summerPackChoiceEligible($app)) {
            return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/informations');
        }
        $choice = (string) ($body['pack_choice'] ?? '');
        if (!in_array($choice, ['ete', 'next'], true)) {
            return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/formule-saison');
        }

        $currentSeason = Season::fromDate(new DateTimeImmutable());
        $summerPack = $choice === 'ete';
        $season = $summerPack ? $currentSeason : $currentSeason->next();

        $this->applications->update((int) $app['id'], [
            'season_start_year' => $season->startYear,
            'summer_pack'       => (int) $summerPack,
            // Chosen under the old season/pack assumption — wipe rather than
            // leave stale, so the wizard naturally re-asks Formule (every
            // later step already redirects there when this is empty).
            'subscription_type' => '',
            'is_couple'         => 0,
            'lessons_count'     => 0,
        ]);
        $this->applications->removePartner((int) $app['id']);

        $next = $this->applicantIsMinor($app) ? 'representant-legal' : 'formule';
        return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/' . $next);
    }

    // ── Step 2: legal guardian (minors only) ────────────────────────────

    public function showGuardian(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        if (!$this->applicantIsMinor($app)) {
            return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/formule');
        }
        return $this->renderGuardian($response, $app, [], []);
    }

    public function submitGuardian(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        if (!$this->applicantIsMinor($app)) {
            return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/formule');
        }
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $this->renderGuardian($response, $app, $body, ['Session expirée, merci de réessayer.']);
        }

        $errors = [];
        $guardianFullname = trim((string) ($body['guardian_fullname'] ?? ''));
        $guardianEmail = mb_strtolower(trim((string) ($body['guardian_email'] ?? '')));
        $guardianPhone = trim((string) ($body['guardian_phone'] ?? ''));
        if ($guardianFullname === '') {
            $errors[] = 'Le nom du représentant légal est requis pour un mineur.';
        }
        if (!filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'L\'email du représentant légal est invalide.';
        }
        if ($guardianPhone === '') {
            $errors[] = 'Le téléphone du représentant légal est requis.';
        }
        if ($errors !== []) {
            return $this->renderGuardian($response, $app, $body, $errors);
        }

        $people = $this->applications->people((int) $app['id']);
        $applicant = $people[1];
        $applicant['guardian_fullname'] = $guardianFullname;
        $applicant['guardian_email'] = $guardianEmail;
        $applicant['guardian_phone'] = $guardianPhone;
        $this->applications->savePerson((int) $app['id'], 1, $applicant);
        $this->applications->update((int) $app['id'], ['email' => $guardianEmail]);

        $link = $this->baseUrl($request) . '/inscription/' . $app['token'] . '/formule';
        $this->mailer->send(
            $guardianEmail,
            'Votre demande d\'adhésion — Bad & Squash',
            '<p>Bonjour,</p><p>Une demande d\'adhésion a été commencée pour '
            . htmlspecialchars(trim($applicant['firstname'] . ' ' . $applicant['lastname']), ENT_QUOTES)
            . ', dont vous êtes le représentant légal. Vous pouvez la suivre ou la reprendre à tout moment via ce lien :</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Reprendre la demande</a></p>',
            'application_resume',
        );

        return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/formule');
    }

    // ── Step 3: formula ──────────────────────────────────────────────────

    public function showFormule(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        return $this->renderFormule($response, $app, [], []);
    }

    public function submitFormule(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $this->renderFormule($response, $app, $body, ['Session expirée, merci de réessayer.']);
        }

        $errors = [];
        $people = $this->applications->people((int) $app['id']);
        $applicant = $people[1];
        $season = new Season((int) $app['season_start_year']);
        $isJeune = $this->pricing->isJeune(new DateTimeImmutable($applicant['birthdate']), $season);

        // Pack été is fixed to Heures Pleines — no formula choice, ignore whatever the
        // client posted (there's no radio to tamper with, but stay defensive regardless).
        $subscriptionKey = $app['summer_pack'] ? 'heures-pleines' : (string) ($body['subscription'] ?? '');
        $available = $this->availableSubscriptions($app['residence'], $isJeune, $season, (bool) $app['midi_residency_override']);
        if (!isset($available[$subscriptionKey])) {
            $errors[] = 'Merci de choisir un abonnement.';
        }

        $competitor = !empty($body['competitor']);
        $lessonsCount = 0;
        $subscription = $available[$subscriptionKey] ?? null;
        $isCouple = $subscription !== null && !empty($body['is_couple']) && !empty($subscription['couple_available']) && !$app['summer_pack'];

        if ($subscription !== null) {
            if ($subscription['audience'] !== 'jeune' && !$app['summer_pack']) {
                $lessonsCount = min((int) !empty($body['lessons_1']), 1) + ($isCouple ? (int) !empty($body['lessons_2']) : 0);
            }
            if (!$isCouple) {
                $this->applications->removePartner((int) $app['id']);
            }
        }

        if ($errors !== []) {
            return $this->renderFormule($response, $app, $body, $errors);
        }

        $applicantUpdate = $applicant;
        $applicantUpdate['competitor'] = (int) ($competitor && $subscription['audience'] !== 'jeune' && !$app['summer_pack']);
        $this->applications->savePerson((int) $app['id'], 1, $applicantUpdate);
        $this->applications->update((int) $app['id'], [
            'subscription_type' => $subscriptionKey,
            'is_couple'         => (int) $isCouple,
            'lessons_count'     => $lessonsCount,
        ]);

        $next = $isCouple ? 'conjoint' : 'documents';
        return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/' . $next);
    }

    // ── Step 4: couple partner ───────────────────────────────────────────

    public function showConjoint(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        if (empty($app['is_couple'])) {
            return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/documents');
        }
        return $this->renderConjoint($response, $app, [], []);
    }

    public function submitConjoint(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        if (empty($app['is_couple'])) {
            return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/documents');
        }
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $this->renderConjoint($response, $app, $body, ['Session expirée, merci de réessayer.']);
        }

        $people = $this->applications->people((int) $app['id']);
        $applicant = $people[1];
        $errors = [];
        [$partner, $partnerErrors] = $this->validateIdentity($body, requireAddress: false);
        foreach ($partnerErrors as $e) {
            $errors[] = 'Conjoint(e) : ' . $e;
        }
        if ($partnerErrors === [] && $partner['is_minor']) {
            $errors[] = 'L\'inscription en couple est réservée à deux adultes.';
        }
        if ($errors !== []) {
            return $this->renderConjoint($response, $app, $body, $errors);
        }

        $partner['competitor'] = (int) !empty($body['competitor']);
        $partner['address'] = $applicant['address'];
        $partner['postalcode'] = $applicant['postalcode'];
        $partner['city'] = $applicant['city'];
        $this->applications->savePerson((int) $app['id'], 2, $partner);

        return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/documents');
    }

    // ── Step 5: documents ────────────────────────────────────────────────

    public function showDocuments(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        return $this->renderDocuments($response, $app, []);
    }

    public function submitDocuments(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $this->renderDocuments($response, $app, ['Session expirée, merci de réessayer.']);
        }

        $errors = [];
        foreach ($request->getUploadedFiles() as $field => $file) {
            // Field names: photo_1, photo_2, justificatif_1
            if ($file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (preg_match('/^(photo|justificatif)_([12])$/', (string) $field, $m)) {
                try {
                    $this->uploads->store($file, (int) $app['id'], (int) $m[2], $m[1]);
                } catch (\RuntimeException $e) {
                    $errors[] = ucfirst($m[1]) . ' : ' . $e->getMessage();
                }
            }
        }

        if ($errors !== []) {
            return $this->renderDocuments($response, $app, $errors);
        }

        $missing = $this->missingDocuments($app);
        if ($missing !== []) {
            return $this->renderDocuments($response, $app, []);
        }

        $next = $this->applicantIsMinor($app) ? 'sante' : $this->nextAfterHealthStep($app);
        return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/' . $next);
    }

    // ── Step 6: minor health ─────────────────────────────────────────────

    public function showSante(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        if (!$this->applicantIsMinor($app)) {
            return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/' . $this->nextAfterHealthStep($app));
        }
        return $this->renderSante($response, $app, [], []);
    }

    public function submitSante(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $this->renderSante($response, $app, $body, ['Session expirée, merci de réessayer.']);
        }

        $people = $this->applications->people((int) $app['id']);
        $minor = $people[1];
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
                        'applications/' . $app['id'],
                        $minor,
                        $guardian,
                        $minor['guardian_email'],
                        $place,
                        (string) ($body['signature'] ?? ''),
                        $ip,
                    );
                    $this->applications->saveAttestation((int) $app['id'], 1, [
                        'outcome'           => 'all_negative',
                        'guardian_fullname' => $guardian,
                        'signature_ip'      => $ip,
                        'signed_at'         => date('Y-m-d H:i:s'),
                        'pdf_stored_name'   => $pdfName,
                    ]);
                    $this->auditLog->log((string) $app['email'], 'application_attestation.signed', 'application', (string) $app['id'], [
                        'outcome' => 'all_negative', 'guardian_fullname' => $guardian,
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
                    $this->uploads->store($file, (int) $app['id'], 1, 'medical_certificate');
                    $this->applications->saveAttestation((int) $app['id'], 1, ['outcome' => 'certificate']);
                    $this->auditLog->log((string) $app['email'], 'application_attestation.signed', 'application', (string) $app['id'], [
                        'outcome' => 'certificate',
                    ]);
                } catch (\RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        } else {
            $errors[] = 'Merci de choisir une option.';
        }

        if ($errors !== []) {
            return $this->renderSante($response, $app, $body, $errors);
        }

        $next = $this->nextAfterHealthStep($app);
        return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/' . $next);
    }

    // ── Step 7: licence ──────────────────────────────────────────────────

    public function showLicence(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        if ($app['subscription_type'] === '') {
            return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/formule');
        }
        if ($this->isJeuneApplication($app)) {
            return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/recapitulatif');
        }
        return $this->renderLicence($response, $app, []);
    }

    public function submitLicence(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        if ($app['subscription_type'] === '') {
            return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/formule');
        }
        if ($this->isJeuneApplication($app)) {
            return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/recapitulatif');
        }
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $this->renderLicence($response, $app, ['Session expirée, merci de réessayer.']);
        }

        $people = $this->applications->people((int) $app['id']);
        $errors = [];
        foreach (array_keys($people) as $position) {
            $choice = (string) ($body['licence_' . $position] ?? 'keep');
            $reason = trim((string) ($body['licence_reason_' . $position] ?? ''));
            if ($choice === 'exception' && $reason === '') {
                $errors[] = 'Merci d\'indiquer le motif du retrait de la licence' . ($position === 2 ? ' de votre conjoint(e)' : '') . '.';
            }
        }
        if ($errors !== []) {
            return $this->renderLicence($response, $app, $errors);
        }

        foreach (array_keys($people) as $position) {
            $removed = ($body['licence_' . $position] ?? 'keep') === 'exception';
            $reason = trim((string) ($body['licence_reason_' . $position] ?? ''));
            $this->applications->updatePersonLicence((int) $app['id'], $position, $removed, $removed ? $reason : '');
        }

        return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/recapitulatif');
    }

    // ── Step 8: recap & submit ───────────────────────────────────────────

    public function showRecap(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        return $this->renderRecap($response, $app, []);
    }

    public function submitRecap(Request $request, Response $response, array $args): Response
    {
        $app = $this->loadDraft($args['token']);
        if ($app === null) {
            return $this->redirectByStatus($response, $args['token']);
        }
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $this->renderRecap($response, $app, ['Session expirée, merci de réessayer.']);
        }

        $problems = $this->completenessProblems($app);
        if ($problems !== []) {
            return $this->renderRecap($response, $app, $problems);
        }

        $this->applications->setStatus((int) $app['id'], 'submitted', ['submitted_at' => date('Y-m-d H:i:s')]);
        $this->logger->info('prospect', 'Application submitted', ['application_id' => (int) $app['id']]);

        $this->mailer->send(
            $app['email'],
            'Demande d\'adhésion reçue — Bad & Squash',
            '<p>Bonjour,</p><p>Votre demande d\'adhésion a bien été transmise au club. '
            . 'Elle sera examinée par un responsable ; vous recevrez alors un email pour procéder au paiement.</p>'
            . '<p>Suivre ma demande : <a href="' . htmlspecialchars($this->baseUrl($request) . '/inscription/' . $app['token'] . '/confirmation', ENT_QUOTES) . '">état de ma demande</a></p>',
            'application_submitted',
        );

        return $response->withStatus(302)->withHeader('Location', '/inscription/' . $app['token'] . '/confirmation');
    }

    public function showConfirmation(Request $request, Response $response, array $args): Response
    {
        $app = $this->applications->findByToken($args['token']);
        if ($app === null) {
            return $response->withStatus(302)->withHeader('Location', '/');
        }
        return $this->renderer->render($response, 'pages/join_confirmation.php', [
            'title' => 'Ma demande d\'adhésion',
            'app'   => $app,
        ]);
    }

    // ── Shared helpers ───────────────────────────────────────────────────

    private function loadDraft(string $token): ?array
    {
        $app = $this->applications->findByToken($token);
        return ($app !== null && $app['status'] === 'draft') ? $app : null;
    }

    private function redirectByStatus(Response $response, string $token): Response
    {
        $app = $this->applications->findByToken($token);
        $target = $app === null ? '/inscription' : '/inscription/' . $token . '/confirmation';
        return $response->withStatus(302)->withHeader('Location', $target);
    }

    /** @return array{0: array, 1: string[]} person fields + errors */
    private function validateIdentity(array $body, bool $requireAddress): array
    {
        $errors = [];
        $person = [
            'firstname'  => trim((string) ($body['firstname'] ?? '')),
            'lastname'   => trim((string) ($body['lastname'] ?? '')),
            'sex'        => in_array($body['sex'] ?? '', ['M', 'W'], true) ? $body['sex'] : '',
            'birthdate'  => trim((string) ($body['birthdate'] ?? '')),
            'email'      => mb_strtolower(trim((string) ($body['email'] ?? ''))),
            'phone'      => trim((string) ($body['phone'] ?? '')),
            'address'    => trim((string) ($body['address'] ?? '')),
            'postalcode' => trim((string) ($body['postalcode'] ?? '')),
            'city'       => trim((string) ($body['city'] ?? '')),
        ];

        if ($person['firstname'] === '' || mb_strlen($person['firstname']) > 50) {
            $errors[] = 'Le prénom est requis (50 caractères max).';
        }
        if ($person['lastname'] === '' || mb_strlen($person['lastname']) > 50) {
            $errors[] = 'Le nom est requis (50 caractères max).';
        }
        if ($person['sex'] === '') {
            $errors[] = 'Le sexe est requis.';
        }

        $birthdate = DateTimeImmutable::createFromFormat('Y-m-d', $person['birthdate']) ?: null;
        if ($birthdate === null || $birthdate > new DateTimeImmutable() || $birthdate < new DateTimeImmutable('1900-01-01')) {
            $errors[] = 'La date de naissance est invalide.';
            $birthdate = null;
        }
        if (!filter_var($person['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'L\'adresse email est invalide.';
        }
        if ($person['phone'] === '') {
            $errors[] = 'Le téléphone est requis.';
        }
        if ($requireAddress) {
            if ($person['address'] === '' || $person['city'] === '') {
                $errors[] = 'L\'adresse postale complète est requise.';
            }
            if (!preg_match('/^\d{5}$/', $person['postalcode'])) {
                $errors[] = 'Le code postal doit comporter 5 chiffres.';
            }
        }

        $person['is_minor'] = 0;
        if ($birthdate !== null) {
            $person['is_minor'] = (int) $this->pricing->isMinor($birthdate, new DateTimeImmutable());
        }

        return [$person, $errors];
    }

    /** @return array<string, array> */
    private function availableSubscriptions(string $residence, bool $isJeune, Season $season, bool $midiResidencyOverride): array
    {
        $subscriptions = $this->pricing->subscriptionsFor($residence, $season, $midiResidencyOverride);
        return array_filter(
            $subscriptions,
            fn (array $s) => $isJeune ? $s['audience'] === 'jeune' : $s['audience'] !== 'jeune'
        );
    }

    private function applicantIsMinor(array $app): bool
    {
        $people = $this->applications->people((int) $app['id']);
        return !empty($people[1]['is_minor']);
    }

    /**
     * Whether the Pack été / next-season fork can still be (re)offered for
     * this draft — the same eligibility submitStart() checks at creation
     * time (isSummerPackApplication(), not Jeune, next season published),
     * re-derived fresh against "now" and the applicant's own birthdate
     * (not $app['subscription_type'], which may be blank or stale here)
     * so it naturally stops offering itself once the summer window passes.
     */
    private function summerPackChoiceEligible(array $app): bool
    {
        $now = new DateTimeImmutable();
        if (!self::isSummerPackApplication($now)) {
            return false;
        }
        $people = $this->applications->people((int) $app['id']);
        $birthdate = $people[1]['birthdate'] ?? '';
        if ($birthdate === '') {
            return false;
        }
        $season = Season::fromDate($now);
        if ($this->pricing->isJeune(new DateTimeImmutable($birthdate), $season)) {
            return false;
        }
        return $this->pricing->hasCatalogue($season->next());
    }

    private function isJeuneApplication(array $app): bool
    {
        if ($app['subscription_type'] === '') {
            return false;
        }
        $season = new Season((int) $app['season_start_year']);
        return $this->pricing->subscription($app['subscription_type'], $season)['audience'] === 'jeune';
    }

    /** Step after santé (itself only shown to minors): licence, unless the jeune formula has none to remove. */
    private function nextAfterHealthStep(array $app): string
    {
        return $this->isJeuneApplication($app) ? 'recapitulatif' : 'licence';
    }

    /** @return string[] missing document labels */
    private function missingDocuments(array $app): array
    {
        $people = $this->applications->people((int) $app['id']);
        $documents = $this->applications->documents((int) $app['id']);
        $missing = [];
        foreach ($people as $position => $person) {
            if (!isset($documents[$position . ':photo'])) {
                $missing[] = 'Photo de profil' . (count($people) > 1 ? " ({$person['firstname']})" : '');
            }
        }
        if ($app['residence'] === PricingService::RESIDENCE_GARENNOIS && !isset($documents['1:justificatif'])) {
            $missing[] = 'Justificatif de domicile (tarif Garennois)';
        }
        return $missing;
    }

    /** @return string[] French messages listing what still blocks submission */
    private function completenessProblems(array $app): array
    {
        $problems = [];
        if ($app['subscription_type'] === '') {
            $problems[] = 'Choisissez votre abonnement.';
        }
        foreach ($this->missingDocuments($app) as $doc) {
            $problems[] = 'Document manquant : ' . $doc . '.';
        }
        if ($this->applicantIsMinor($app) && !isset($this->applications->attestations((int) $app['id'])[1])) {
            $problems[] = 'L\'attestation de santé (ou le certificat médical) du mineur est requise.';
        }
        return $problems;
    }

    public function buildQuote(array $app): ?Quote
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
        );
    }

    private function baseUrl(Request $request): string
    {
        $uri = $request->getUri();
        return $uri->getScheme() . '://' . $uri->getAuthority();
    }

    /**
     * Best-effort check for an existing BJ member with this email — steers
     * them to renewal instead of a duplicate join. Fails open (treats a BJ
     * outage as "no match") since the join wizard otherwise never depends
     * on BJ being reachable until fulfillment.
     */
    private function findBjUserByEmail(string $email): ?array
    {
        try {
            $data = $this->bj->get('users', ['search' => $email, 'limit' => 50]);
        } catch (\RuntimeException $e) {
            $this->logger->error('prospect', 'BJ existing-member lookup failed', ['error' => $e->getMessage()]);
            return null;
        }
        foreach ($data['users'] ?? [] as $user) {
            if (mb_strtolower($user['email'] ?? '') === $email || mb_strtolower($user['email2'] ?? '') === $email) {
                return $user;
            }
        }
        return null;
    }

    // ── Step indicator ───────────────────────────────────────────────────

    /**
     * Builds the dynamic step list for the wizard's visual indicator. Steps
     * not yet known to apply (e.g. couple status before formule is
     * submitted) are simply omitted until they're known — the indicator
     * grows as the applicant's situation becomes clear.
     *
     * @return array{key:string,label:string,state:string}[]
     */
    private function wizardSteps(?array $app, string $current): array
    {
        $isMinor = $app !== null && $this->applicantIsMinor($app);
        $isCouple = $app !== null && !empty($app['is_couple']);
        $isJeune = $app !== null && $this->isJeuneApplication($app);

        $steps = [['key' => 'identity', 'label' => 'Vos informations']];
        if ($isMinor) {
            $steps[] = ['key' => 'representant-legal', 'label' => 'Représentant légal'];
        }
        $steps[] = ['key' => 'formule', 'label' => 'Formule'];
        if ($isCouple) {
            $steps[] = ['key' => 'conjoint', 'label' => 'Conjoint(e)'];
        }
        $steps[] = ['key' => 'documents', 'label' => 'Documents'];
        if ($isMinor) {
            $steps[] = ['key' => 'sante', 'label' => 'Santé'];
        }
        if (!$isJeune) {
            $steps[] = ['key' => 'licence', 'label' => 'Licence'];
        }
        $steps[] = ['key' => 'recapitulatif', 'label' => 'Récapitulatif'];

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
     * Step keys map 1:1 to their URL segment — except 'identity', whose
     * token-based edit page is /informations (the bare /inscription page has
     * no token and would create a fresh application instead of editing this
     * one, see submitStart()).
     */
    private function previousStepUrl(array $steps, string $current, string $token): ?string
    {
        $keys = array_column($steps, 'key');
        $index = array_search($current, $keys, true);
        if ($index === false || $index === 0) {
            return null;
        }
        $previous = $keys[$index - 1] === 'identity' ? 'informations' : $keys[$index - 1];
        return '/inscription/' . $token . '/' . $previous;
    }

    // ── Rendering ────────────────────────────────────────────────────────

    private function renderStart(
        Response $response,
        array $old,
        array $errors,
        bool $existingMember = false,
        bool $jeuneNotYetOpen = false,
    ): Response {
        return $this->renderer->render($response, 'pages/join_start.php', [
            'title'             => 'Demande d\'adhésion',
            'csrf'              => Csrf::token(),
            'steps'             => $this->wizardSteps(null, 'identity'),
            'old'               => $old,
            'errors'            => $errors,
            'existingMember'    => $existingMember,
            'jeuneNotYetOpen'   => $jeuneNotYetOpen,
        ]);
    }

    private function renderInformations(Response $response, array $app, array $old, array $errors, bool $existingMember = false): Response
    {
        return $this->renderer->render($response, 'pages/join_informations.php', [
            'title'                    => 'Vos informations',
            'csrf'                     => Csrf::token(),
            'app'                      => $app,
            'steps'                    => $this->wizardSteps($app, 'identity'),
            'old'                      => $old,
            'errors'                   => $errors,
            'existingMember'           => $existingMember,
            'summerPackChoiceEligible' => $this->summerPackChoiceEligible($app),
        ]);
    }

    private function renderGuardian(Response $response, array $app, array $old, array $errors): Response
    {
        $people = $this->applications->people((int) $app['id']);
        $steps = $this->wizardSteps($app, 'representant-legal');
        return $this->renderer->render($response, 'pages/join_guardian.php', [
            'title'   => 'Représentant légal',
            'csrf'    => Csrf::token(),
            'app'     => $app,
            'minor'   => $people[1],
            'steps'   => $steps,
            'backUrl' => $this->previousStepUrl($steps, 'representant-legal', $app['token']),
            'old'     => $old,
            'errors'  => $errors,
        ]);
    }

    private function renderFormule(Response $response, array $app, array $old, array $errors): Response
    {
        $people = $this->applications->people((int) $app['id']);
        $season = new Season((int) $app['season_start_year']);
        $isJeune = $this->pricing->isJeune(new DateTimeImmutable($people[1]['birthdate']), $season);
        $steps = $this->wizardSteps($app, 'formule');

        // Précédent skips straight to the Pack été / next-season fork, not the
        // normal previous step — that fork is what most people going back from
        // here actually want to reconsider, and it's otherwise unreachable.
        $backUrl = $this->summerPackChoiceEligible($app)
            ? '/inscription/' . $app['token'] . '/formule-saison'
            : $this->previousStepUrl($steps, 'formule', $app['token']);

        return $this->renderer->render($response, 'pages/join_formule.php', [
            'title'         => 'Choix de l\'abonnement',
            'csrf'          => Csrf::token(),
            'app'           => $app,
            'people'        => $people,
            'subscriptions' => $this->availableSubscriptions($app['residence'], $isJeune, $season, (bool) $app['midi_residency_override']),
            'isJeune'       => $isJeune,
            'steps'         => $steps,
            'backUrl'       => $backUrl,
            'old'           => $old,
            'errors'        => $errors,
        ]);
    }

    private function renderConjoint(Response $response, array $app, array $old, array $errors): Response
    {
        $people = $this->applications->people((int) $app['id']);
        $steps = $this->wizardSteps($app, 'conjoint');
        return $this->renderer->render($response, 'pages/join_conjoint.php', [
            'title'   => 'Conjoint(e)',
            'csrf'    => Csrf::token(),
            'app'     => $app,
            'partner' => $people[2] ?? null,
            'steps'   => $steps,
            'backUrl' => $this->previousStepUrl($steps, 'conjoint', $app['token']),
            'old'     => $old,
            'errors'  => $errors,
        ]);
    }

    private function renderDocuments(Response $response, array $app, array $errors): Response
    {
        $steps = $this->wizardSteps($app, 'documents');
        return $this->renderer->render($response, 'pages/join_documents.php', [
            'title'     => 'Documents',
            'csrf'      => Csrf::token(),
            'app'       => $app,
            'people'    => $this->applications->people((int) $app['id']),
            'documents' => $this->applications->documents((int) $app['id']),
            'missing'   => $this->missingDocuments($app),
            'steps'     => $steps,
            'backUrl'   => $this->previousStepUrl($steps, 'documents', $app['token']),
            'errors'    => $errors,
        ]);
    }

    private function renderSante(Response $response, array $app, array $old, array $errors): Response
    {
        $people = $this->applications->people((int) $app['id']);
        $steps = $this->wizardSteps($app, 'sante');
        return $this->renderer->render($response, 'pages/join_sante.php', [
            'title'       => 'Santé du mineur',
            'csrf'        => Csrf::token(),
            'app'         => $app,
            'minor'       => $people[1],
            'attestation' => $this->applications->attestations((int) $app['id'])[1] ?? null,
            'steps'       => $steps,
            'backUrl'     => $this->previousStepUrl($steps, 'sante', $app['token']),
            'old'         => $old,
            'errors'      => $errors,
        ]);
    }

    private function renderLicence(Response $response, array $app, array $errors): Response
    {
        $steps = $this->wizardSteps($app, 'licence');
        $people = $this->applications->people((int) $app['id']);
        $season = new Season((int) $app['season_start_year']);
        $subscription = $this->pricing->subscription($app['subscription_type'], $season);
        foreach ($people as &$person) {
            $kind = $app['summer_pack'] ? 'ete' : $this->pricing->licenceKindFor($subscription['audience'], (bool) $person['competitor']);
            $person['licenceInfo'] = $this->pricing->licenceInfo($kind, $season);
        }
        unset($person);

        return $this->renderer->render($response, 'pages/join_licence.php', [
            'title'   => 'Licence',
            'csrf'    => Csrf::token(),
            'app'     => $app,
            'people'  => $people,
            'steps'   => $steps,
            'backUrl' => $this->previousStepUrl($steps, 'licence', $app['token']),
            'errors'  => $errors,
        ]);
    }

    private function renderRecap(Response $response, array $app, array $problems): Response
    {
        $steps = $this->wizardSteps($app, 'recapitulatif');
        return $this->renderer->render($response, 'pages/join_recap.php', [
            'title'        => 'Récapitulatif',
            'csrf'         => Csrf::token(),
            'app'          => $app,
            'people'       => $this->applications->people((int) $app['id']),
            'quote'        => $this->buildQuote($app),
            'subscription' => $app['subscription_type'] !== ''
                ? $this->pricing->subscription($app['subscription_type'], new Season((int) $app['season_start_year']))
                : null,
            'steps'        => $steps,
            'backUrl'      => $this->previousStepUrl($steps, 'recapitulatif', $app['token']),
            'problems'     => $problems,
        ]);
    }
}
