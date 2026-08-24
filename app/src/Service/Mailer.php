<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Db;
use App\Support\Logger;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Outbound email via Google Workspace SMTP (app password), sender
 * nepasrepondre@bad-squash.org. Every attempt is recorded in email_log.
 *
 * Dev fallback: when no SMTP password is configured, the email is not sent —
 * its subject and body are written to the application log instead, so flows
 * (magic links…) remain testable locally.
 */
class Mailer
{
    /** @param array{host?:string,port?:int,username?:string,password?:string,from?:string,from_name?:string} $smtp */
    public function __construct(
        private readonly array $smtp,
        private readonly Db $db,
        private readonly Logger $logger,
    ) {
    }

    public function send(string $to, string $subject, string $htmlBody, string $template = ''): bool
    {
        if (($this->smtp['password'] ?? '') === '') {
            preg_match_all('/href="([^"]+)"/', $htmlBody, $links);
            $this->logger->info('mailer', 'SMTP non configuré — email non envoyé (mode dev)', [
                'to' => $to, 'subject' => $subject, 'links' => $links[1], 'body' => strip_tags($htmlBody),
            ]);
            $this->log($to, $subject, $template, 'sent', '[dev] SMTP non configuré');
            return true;
        }

        $mail = new PHPMailer(true);
        try {
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
            $mail->send();

            $this->log($to, $subject, $template, 'sent');
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('mailer', 'Envoi échoué', ['to' => $to, 'error' => $e->getMessage()]);
            $this->log($to, $subject, $template, 'failed', $e->getMessage());
            return false;
        }
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
