<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SettingsRepository;
use App\Support\Db;
use App\Support\Logger;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Outbound email via Google Workspace SMTP (app password), sender
 * nepasrepondre@bad-squash.org. Every message is recorded in email_log — one
 * row per message, carrying the final outcome, so the log answers "did this
 * person get it" rather than "how many times did we try".
 *
 * A failed send is retried, because it used to be lost outright: nothing here
 * re-attempted, and nothing read email_log, so a momentary inability to reach
 * SMTP silently dropped the message. That is survivable for a magic link, where
 * the member is at their screen and clicks again — which is exactly how the one
 * observed case recovered, two seconds later. It is not survivable for anything
 * the app sends unprompted (invoices, an application's resume link, the notice
 * that an installment charge failed): nobody is waiting to press the button, so
 * the member simply never hears.
 *
 * Dev fallback: when no SMTP password is configured, the email is not sent —
 * its subject and body are written to the application log instead, so flows
 * (magic links…) remain testable locally.
 */
class Mailer
{
    /**
     * Three attempts, spaced by RETRY_BACKOFF_SECONDS. Sends happen inline in a
     * member's request, so the ceiling matters as much as the retrying: this
     * adds at most ~3s to a send that was going to fail anyway, and the observed
     * outage recovered well inside that.
     */
    private const int MAX_ATTEMPTS = 3;

    /** Waits before attempts 2 and 3. */
    private const array RETRY_BACKOFF_SECONDS = [1, 2];

    /** @param array{host?:string,port?:int,username?:string,password?:string,from?:string,from_name?:string} $smtp */
    public function __construct(
        private readonly array $smtp,
        private readonly Db $db,
        private readonly Logger $logger,
        private readonly SettingsRepository $settings,
    ) {
    }

    /** @param list<array{filename:string,content:string,mime:string}> $attachments */
    public function send(string $to, string $subject, string $htmlBody, string $template = '', array $attachments = []): bool
    {
        $htmlBody .= $this->signatureHtml();

        if (($this->smtp['password'] ?? '') === '') {
            preg_match_all('/href="([^"]+)"/', $htmlBody, $links);
            $this->logger->info('mailer', 'SMTP non configuré — email non envoyé (mode dev)', [
                'to' => $to, 'subject' => $subject, 'links' => $links[1], 'body' => strip_tags($htmlBody),
                'attachments' => array_map(
                    fn (array $a) => ['filename' => $a['filename'], 'mime' => $a['mime'], 'bytes' => strlen($a['content'])],
                    $attachments
                ),
            ]);
            $this->log($to, $subject, $template, 'sent', '[dev] SMTP non configuré');
            return true;
        }

        $failures = [];
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $this->deliver($to, $subject, $htmlBody, $attachments);
            } catch (\Throwable $e) {
                $failures[] = $e->getMessage();
                $lastAttempt = $attempt === self::MAX_ATTEMPTS || !self::isWorthRetrying($e);
                $this->logger->error('mailer', $lastAttempt ? 'Envoi échoué' : 'Envoi échoué, nouvelle tentative', [
                    'to' => $to, 'attempt' => $attempt, 'error' => $e->getMessage(),
                ]);
                if ($lastAttempt) {
                    break;
                }
                $this->pause(self::RETRY_BACKOFF_SECONDS[$attempt - 1] ?? 1);
                continue;
            }

            // Earlier failures go in the error column of a row whose status is
            // 'sent': the message did arrive, and hiding the wobble would leave
            // a recurring SMTP problem invisible until one finally sticks.
            $this->log($to, $subject, $template, 'sent', $failures === [] ? null : sprintf(
                'envoyé à la tentative %d après %d échec(s) : %s',
                $attempt,
                count($failures),
                implode(' | ', $failures),
            ));
            return true;
        }

        $this->log($to, $subject, $template, 'failed', implode(' | ', $failures));
        return false;
    }

    /**
     * One delivery attempt. Throws on any failure; separated from send() so the
     * retry loop above is testable without a live SMTP server.
     *
     * @param list<array{filename:string,content:string,mime:string}> $attachments
     */
    protected function deliver(string $to, string $subject, string $htmlBody, array $attachments): void
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $this->smtp['host'] ?? 'smtp.gmail.com';
        $mail->Port       = (int) ($this->smtp['port'] ?? 587);
        $mail->SMTPAuth   = true;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Username   = $this->smtp['username'] ?? '';
        $mail->Password   = $this->smtp['password'] ?? '';
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($this->smtp['from'] ?? '', $this->smtp['from_name'] ?? '');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
        foreach ($attachments as $attachment) {
            $mail->addStringAttachment($attachment['content'], $attachment['filename'], PHPMailer::ENCODING_BASE64, $attachment['mime']);
        }
        $mail->send();
    }

    /** Overridden in tests so the retry loop can be exercised without the wait. */
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * PHPMailer rejects a malformed address before it opens any connection, so
     * that failure is deterministic — retrying it only makes a member wait
     * longer for the same answer. Everything else is worth another go: a refused
     * connection, a TLS wobble, a 4xx from Gmail are all things that pass.
     */
    private static function isWorthRetrying(\Throwable $e): bool
    {
        return !str_contains($e->getMessage(), 'Invalid address');
    }

    /**
     * Admin-edited plain text (line breaks only, no HTML) appended to every
     * email — a fail-safe read (like SettingsRepository::isEnabled()) so a
     * DB hiccup drops the signature instead of blocking mail entirely.
     */
    private function signatureHtml(): string
    {
        try {
            $signature = trim((string) ($this->settings->get('email_signature') ?? ''));
        } catch (\Throwable $e) {
            $this->logger->error('mailer', 'Signature illisible, email envoyé sans', ['error' => $e->getMessage()]);
            return '';
        }
        return $signature === '' ? '' : '<hr>' . nl2br(htmlspecialchars($signature, ENT_QUOTES));
    }

    private function log(string $to, string $subject, string $template, string $status, ?string $error = null): void
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO email_log (recipient, subject, template, status, error, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([$to, $subject, $template, $status, $error]);
        } catch (\Throwable $e) {
            $this->logger->error('mailer', 'email_log write failed', ['error' => $e->getMessage()]);
        }
    }
}
