<?php

declare(strict_types=1);

/**
 * One-off: refuses application #34's student-discount request (Sana
 * ECHCHIBEL, subscription_type 'jeune'). Not something the admin UI can do
 * yet — decideStudentDiscount() only exists for an order already sitting in
 * 'awaiting_student_approval', and this application hasn't reached payment,
 * so there is no order row. Mirrors exactly the fields/email/audit entry
 * that endpoint's refuse branch would write for a join application (see
 * AdminOpsController::decideStudentDiscount()), minus the order transition
 * that doesn't apply here.
 *
 * Follows now that PricingService::quote() refuses studentDiscount+jeune
 * together (jeune is already the age-based discounted tier) — leaving this
 * request set would make AdminApplicationController::quoteFor() throw the
 * moment anyone opens /admin/demandes/34 after that fix deploys.
 *
 * Dry-run by default — prints what it would change. Pass --apply to write.
 * Idempotent: exits early if the request has already been refused.
 *
 *   php app/bin/refuse_application_34_student_discount.php            dry run
 *   php app/bin/refuse_application_34_student_discount.php --apply     write for real
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Repository\ApplicationRepository;
use App\Repository\SettingsRepository;
use App\Service\Mailer;
use App\Support\Db;
use App\Support\Logger;

$settings = require dirname(__DIR__) . '/config/settings.php';
$apply = in_array('--apply', $argv, true);

$db = new Db($settings['db']);
$logger = new Logger($settings['paths']['log_file']);
$settingsRepo = new SettingsRepository($db, $logger);
$applications = new ApplicationRepository($db);
$mailer = new Mailer($settings['smtp'], $db, $logger, $settingsRepo);

$applicationId = 34;
$reason = "La réduction étudiant ne s'applique pas à l'abonnement Jeune, déjà à tarif réduit pour les mineurs.";

$app = $applications->findById($applicationId);
if ($app === null) {
    fwrite(STDERR, "Demande #$applicationId introuvable.\n");
    exit(1);
}
if ($app['subscription_type'] !== 'jeune') {
    fwrite(STDERR, "Demande #$applicationId n'est pas en formule Jeune (subscription_type={$app['subscription_type']}) — abandon.\n");
    exit(1);
}
if ($app['student_discount_refused_at'] !== null) {
    echo "Déjà refusé le {$app['student_discount_refused_at']} — rien à faire.\n";
    exit(0);
}
if (empty($app['student_discount_requested'])) {
    echo "student_discount_requested est déjà à 0 — rien à faire.\n";
    exit(0);
}

$people = $applications->people($applicationId);
$applicantName = trim(($people[1]['firstname'] ?? '') . ' ' . ($people[1]['lastname'] ?? ''));
$resumeUrl = 'https://members.bad-squash.org/paiement/' . $app['token'];

echo ($apply ? "MODE ÉCRITURE" : "MODE DRY-RUN (aucune écriture — relancez avec --apply)") . "\n\n";
echo "Demande #$applicationId — $applicantName ({$app['email']})\n";
echo "  student_discount_requested      : {$app['student_discount_requested']} -> 0\n";
echo "  student_discount_refused_at     : (vide) -> maintenant\n";
echo "  student_discount_refusal_reason : \"$reason\"\n";
echo "  Email de refus -> {$app['email']} (lien de reprise : $resumeUrl)\n";
echo "  audit_log: student_discount.reject (application #$applicationId)\n\n";

if (!$apply) {
    exit(0);
}

$applications->update($applicationId, [
    'student_discount_requested'      => 0,
    'student_discount_refused_at'     => date('Y-m-d H:i:s'),
    'student_discount_refusal_reason' => mb_substr($reason, 0, 500),
]);
echo "  ÉCRIT applications#$applicationId\n";

$mailer->send(
    $app['email'],
    'À propos de la réduction étudiant — Adhésion',
    '<p>Bonjour,</p><p>La réduction étudiant ne s\'applique pas à l\'abonnement Jeune, qui est déjà un tarif réduit '
    . 'pour les mineurs — nous ne pouvons donc pas l\'accorder en plus pour l\'adhésion de ' . htmlspecialchars($applicantName, ENT_QUOTES) . '.</p>'
    . '<p>Vous pouvez poursuivre l\'adhésion au tarif Jeune habituel :</p>'
    . '<p><a href="' . htmlspecialchars($resumeUrl, ENT_QUOTES) . '">Continuer</a></p>',
    'student_discount_refused',
);
echo "  ENVOYÉ email de refus\n";

$stmt = $db->pdo()->prepare(
    'INSERT INTO audit_log (actor, action, entity, entity_id, details, created_at)
     VALUES (?, ?, "application", ?, ?, NOW())',
);
$stmt->execute(['system', 'student_discount.reject', (string) $applicationId, json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE)]);
echo "  ÉCRIT audit_log\n";
