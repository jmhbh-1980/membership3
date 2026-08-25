<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SettingsRepository;
use App\Service\Auth\AuthService;
use App\Service\Mailer;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public bug-report endpoint fed by the floating bubble (base.php). Reachable
 * by anonymous visitors and logged-in members alike, never by admins — mode
 * and role are re-checked here even though the UI already hides the bubble,
 * so disabling the mode (or an admin session) genuinely blocks submissions.
 */
final class BugReportController
{
    private const int MAX_SCREENSHOT_BYTES = 5 * 1024 * 1024;
    private const array ALLOWED_MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
    private const int MAX_PER_MINUTE = 5;
    private const int MAX_PER_DAY = 100;
    private const int MAX_FIELD_LENGTH = 80;
    private const int MAX_COMMENT_LENGTH = 4000;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Mailer $mailer,
        private readonly Db $db,
        private readonly Logger $logger,
    ) {
    }

    public function submit(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $this->json($response, 403, ['ok' => false, 'error' => 'csrf']);
        }
        if (!$this->settings->isEnabled('bug_report_mode')) {
            return $this->json($response, 403, ['ok' => false, 'error' => 'disabled']);
        }
        $user = AuthService::currentUser();
        if ($user !== null && $user['role'] === AuthService::ROLE_ADMIN) {
            return $this->json($response, 403, ['ok' => false, 'error' => 'admin']);
        }
        if ($this->isRateLimited()) {
            return $this->json($response, 429, ['ok' => false, 'error' => 'rate_limited']);
        }

        $firstname = trim((string) ($body['firstname'] ?? ''));
        $lastname  = trim((string) ($body['lastname'] ?? ''));
        $comment   = trim((string) ($body['comment'] ?? ''));
        $pageUrl   = preg_replace('/[\r\n]+/', ' ', trim((string) ($body['page_url'] ?? ''))) ?? '';

        $errors = [];
        if ($firstname === '' || mb_strlen($firstname) > self::MAX_FIELD_LENGTH) {
            $errors[] = 'Prénom requis (' . self::MAX_FIELD_LENGTH . ' caractères maximum).';
        }
        if ($lastname === '' || mb_strlen($lastname) > self::MAX_FIELD_LENGTH) {
            $errors[] = 'Nom requis (' . self::MAX_FIELD_LENGTH . ' caractères maximum).';
        }
        if ($comment === '' || mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            $errors[] = 'Description requise (' . self::MAX_COMMENT_LENGTH . ' caractères maximum).';
        }
        if ($errors !== []) {
            return $this->json($response, 422, ['ok' => false, 'errors' => $errors]);
        }

        $htmlBody = '<p>Prénom : ' . htmlspecialchars($firstname, ENT_QUOTES) . '</p>'
            . '<p>Nom : ' . htmlspecialchars($lastname, ENT_QUOTES) . '</p>'
            . '<p>Page : ' . htmlspecialchars($pageUrl, ENT_QUOTES) . '</p>'
            . '<p>Commentaire :<br>' . nl2br(htmlspecialchars($comment, ENT_QUOTES)) . '</p>';

        $ok = $this->mailer->send(
            'squash+bug@bad-squash.org',
            'Signalement de bug — ' . $pageUrl,
            $htmlBody,
            'bug_report',
            $this->screenshotAttachment($request),
        );

        return $ok
            ? $this->json($response, 200, ['ok' => true])
            : $this->json($response, 502, ['ok' => false, 'error' => 'mail_failed']);
    }

    /** @return list<array{filename:string,content:string,mime:string}> */
    private function screenshotAttachment(Request $request): array
    {
        $file = $request->getUploadedFiles()['screenshot'] ?? null;
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return [];
        }
        if ($file->getSize() === null || $file->getSize() > self::MAX_SCREENSHOT_BYTES) {
            $this->logger->info('bug_report', 'Capture ignorée (taille)', ['size' => $file->getSize()]);
            return [];
        }

        $tmpPath = $file->getStream()->getMetadata('uri');
        $mime = is_string($tmpPath) ? (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath) : false;
        if ($mime === false || !isset(self::ALLOWED_MIMES[$mime])) {
            $this->logger->info('bug_report', 'Capture ignorée (type de fichier)', ['mime' => $mime]);
            return [];
        }

        return [[
            'filename' => 'capture.' . self::ALLOWED_MIMES[$mime],
            'content'  => (string) file_get_contents($tmpPath),
            'mime'     => $mime,
        ]];
    }

    /**
     * Server-side abuse guard backed by email_log (already written by every
     * Mailer::send() call) — a per-session cooldown would be trivially
     * bypassed by simply not sending the session cookie, so this checks
     * actual send volume instead.
     */
    private function isRateLimited(): bool
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_log WHERE template = 'bug_report' AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() >= self::MAX_PER_MINUTE) {
            return true;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_log WHERE template = 'bug_report' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $stmt->execute();
        return (int) $stmt->fetchColumn() >= self::MAX_PER_DAY;
    }

    private function json(Response $response, int $status, array $payload): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }
}
