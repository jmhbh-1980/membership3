<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\SettingsRepository;
use App\Service\Mailer;
use App\Support\Db;
use App\Support\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exercises Mailer's retry loop through the deliver() seam, so no SMTP server is
 * involved and no wait is served. email_log is the real dev DB (see CLAUDE.md),
 * because the point of the retry is what the log ends up saying.
 */
final class MailerRetryTest extends TestCase
{
    private const string RECIPIENT = 'retry-test@example.invalid';
    private const string LOG_FILE = '/tmp/mailer_retry_test.log';

    private Db $db;

    protected function setUp(): void
    {
        @unlink(self::LOG_FILE);
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->clearLog();
    }

    protected function tearDown(): void
    {
        $this->clearLog();
        @unlink(self::LOG_FILE);
    }

    private function clearLog(): void
    {
        $this->db->pdo()->prepare('DELETE FROM email_log WHERE recipient = ?')->execute([self::RECIPIENT]);
    }

    /** @param list<?string> $outcomes null = this attempt succeeds, string = it throws that message */
    private function mailer(array $outcomes): FakeTransportMailer
    {
        $logger = new Logger(self::LOG_FILE);

        // A non-empty password keeps send() off the dev shortcut, which returns
        // before the retry loop is ever reached.
        return new FakeTransportMailer(
            $outcomes,
            ['password' => 'not-a-real-password'],
            $this->db,
            $logger,
            new SettingsRepository($this->db, $logger),
        );
    }

    /** @return list<array{status:string,error:?string}> */
    private function loggedRows(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, error FROM email_log WHERE recipient = ? ORDER BY id');
        $stmt->execute([self::RECIPIENT]);

        return $stmt->fetchAll();
    }

    public function testASendThatWorksFirstTimeIsNotRetriedAndLogsNoError(): void
    {
        $mailer = $this->mailer([null]);

        self::assertTrue($mailer->send(self::RECIPIENT, 'Sujet', '<p>Corps.</p>', 'test'));
        self::assertSame(1, $mailer->attempts);
        self::assertSame([], $mailer->pauses);

        $rows = $this->loggedRows();
        self::assertCount(1, $rows);
        self::assertSame('sent', $rows[0]['status']);
        self::assertNull($rows[0]['error']);
    }

    /**
     * The case actually seen in production: SMTP unreachable for a moment, fine
     * seconds later. Previously the message was lost; now it arrives.
     */
    public function testRecoversFromATransientFailureAndStillCountsAsSent(): void
    {
        $mailer = $this->mailer(['SMTP Error: Could not connect to SMTP host.', null]);

        self::assertTrue($mailer->send(self::RECIPIENT, 'Sujet', '<p>Corps.</p>', 'test'));
        self::assertSame(2, $mailer->attempts);
        self::assertSame([1], $mailer->pauses, 'should have waited once, for 1 second');

        // One row per message, and its status answers "did they get it".
        $rows = $this->loggedRows();
        self::assertCount(1, $rows);
        self::assertSame('sent', $rows[0]['status']);
        // The wobble is still recorded, so a recurring SMTP problem stays visible.
        self::assertStringContainsString('tentative 2', (string) $rows[0]['error']);
        self::assertStringContainsString('Could not connect', (string) $rows[0]['error']);
    }

    public function testUsesTheFullBackoffBeforeTheThirdAttempt(): void
    {
        $mailer = $this->mailer(['connect failed', 'connect failed', null]);

        self::assertTrue($mailer->send(self::RECIPIENT, 'Sujet', '<p>Corps.</p>', 'test'));
        self::assertSame(3, $mailer->attempts);
        self::assertSame([1, 2], $mailer->pauses);
    }

    public function testGivesUpAfterThreeAttemptsAndRecordsEveryError(): void
    {
        $mailer = $this->mailer(['erreur 1', 'erreur 2', 'erreur 3']);

        self::assertFalse($mailer->send(self::RECIPIENT, 'Sujet', '<p>Corps.</p>', 'test'));
        self::assertSame(3, $mailer->attempts, 'must not keep trying beyond MAX_ATTEMPTS');
        self::assertSame([1, 2], $mailer->pauses);

        $rows = $this->loggedRows();
        self::assertCount(1, $rows, 'a failed message is one row, not one per attempt');
        self::assertSame('failed', $rows[0]['status']);
        foreach (['erreur 1', 'erreur 2', 'erreur 3'] as $message) {
            self::assertStringContainsString($message, (string) $rows[0]['error']);
        }
    }

    /**
     * A malformed address fails identically every time — PHPMailer rejects it
     * before opening a connection — so retrying would only make the member wait
     * longer for the same answer.
     */
    public function testDoesNotRetryAnInvalidAddress(): void
    {
        $mailer = $this->mailer(['Invalid address: (to): pas-une-adresse']);

        self::assertFalse($mailer->send(self::RECIPIENT, 'Sujet', '<p>Corps.</p>', 'test'));
        self::assertSame(1, $mailer->attempts);
        self::assertSame([], $mailer->pauses, 'no wait should have been served');

        $rows = $this->loggedRows();
        self::assertCount(1, $rows);
        self::assertSame('failed', $rows[0]['status']);
    }

    public function testDevModeStillShortCircuitsWithoutTouchingTheTransport(): void
    {
        $logger = new Logger(self::LOG_FILE);
        $mailer = new FakeTransportMailer(
            ['should never be reached'],
            ['password' => ''],
            $this->db,
            $logger,
            new SettingsRepository($this->db, $logger),
        );

        self::assertTrue($mailer->send(self::RECIPIENT, 'Sujet', '<p>Corps.</p>', 'test'));
        self::assertSame(0, $mailer->attempts);
    }
}

/**
 * Replaces the PHPMailer call with a scripted sequence of outcomes, and turns the
 * backoff into a recording rather than a wait.
 */
final class FakeTransportMailer extends Mailer
{
    public int $attempts = 0;

    /** @var list<int> seconds passed to pause(), in order */
    public array $pauses = [];

    /** @param list<?string> $outcomes */
    public function __construct(
        private readonly array $outcomes,
        array $smtp,
        Db $db,
        Logger $logger,
        SettingsRepository $settings,
    ) {
        parent::__construct($smtp, $db, $logger, $settings);
    }

    protected function deliver(string $to, string $subject, string $htmlBody, array $attachments): void
    {
        $outcome = $this->outcomes[$this->attempts] ?? null;
        $this->attempts++;
        if ($outcome !== null) {
            throw new RuntimeException($outcome);
        }
    }

    protected function pause(int $seconds): void
    {
        $this->pauses[] = $seconds;
    }
}
