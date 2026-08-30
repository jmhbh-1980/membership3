<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\SettingsRepository;
use App\Service\Mailer;
use App\Support\Db;
use App\Support\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: no SMTP password configured, so send() takes the dev
 * fallback path (logs instead of sending) — the exact same body-building
 * code runs either way, so this is a real test of signatureHtml(), not a
 * dev-only shortcut. Real Db connection (dev MySQL, see CLAUDE.md);
 * tearDown() clears the settings row.
 */
final class MailerTest extends TestCase
{
    private const string LOG_FILE = '/tmp/mailer_test.log';

    private Mailer $mailer;
    private SettingsRepository $settings;
    private Db $db;

    protected function setUp(): void
    {
        @unlink(self::LOG_FILE);
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $logger = new Logger(self::LOG_FILE);
        $this->settings = new SettingsRepository($this->db, $logger);
        $this->mailer = new Mailer(['password' => ''], $this->db, $logger, $this->settings);
    }

    protected function tearDown(): void
    {
        $this->db->pdo()->prepare('DELETE FROM settings WHERE name = ?')->execute(['email_signature']);
        $this->db->pdo()->prepare('DELETE FROM email_log WHERE recipient = ?')->execute(['test@example.invalid']);
        @unlink(self::LOG_FILE);
    }

    public function testNoSignatureConfiguredLeavesBodyUnchanged(): void
    {
        $this->mailer->send('test@example.invalid', 'Sujet', '<p>Corps du message.</p>', 'test');

        $logged = (string) file_get_contents(self::LOG_FILE);
        self::assertStringContainsString('Corps du message.', $logged);
        self::assertStringNotContainsString('<hr>', $logged);
    }

    public function testConfiguredSignatureIsAppendedToEveryEmail(): void
    {
        $this->settings->set('email_signature', "Cordialement,\nLe bureau — Bad & Squash");

        $this->mailer->send('test@example.invalid', 'Sujet', '<p>Corps du message.</p>', 'test');

        $logged = (string) file_get_contents(self::LOG_FILE);
        self::assertStringContainsString('Corps du message.', $logged);
        self::assertStringContainsString('Cordialement,', $logged);
        self::assertStringContainsString('Le bureau', $logged);
    }

    public function testSignatureIsHtmlEscaped(): void
    {
        $this->settings->set('email_signature', '<script>alert(1)</script>');

        $this->mailer->send('test@example.invalid', 'Sujet', '<p>Corps.</p>', 'test');

        $logged = (string) file_get_contents(self::LOG_FILE);
        self::assertStringNotContainsString('<script>', $logged);
    }
}
