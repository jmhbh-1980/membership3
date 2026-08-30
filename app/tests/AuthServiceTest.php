<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\AuditLogRepository;
use App\Repository\SettingsRepository;
use App\Service\Auth\AuthService;
use App\Service\BalleJaune\BalleJauneClient;
use App\Support\Db;
use App\Support\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Covers AuthService::clearIfInvalidated() — the deploy-time force-logout
 * hook (see bin/maintenance.php, App\Middleware\Maintenance). Integration
 * test against the real dev DB, same pattern as SettingsRepositoryTest.
 */
final class AuthServiceTest extends TestCase
{
    private const string SETTING_KEY = 'sessions_invalidated_at';
    private const string TEST_EMAIL = 'authservicetest@example.invalid';
    // A reserved documentation-range IP (TEST-NET-3, RFC 5737) — never the
    // real dev server's REMOTE_ADDR, so these tests can't be rate-limited by
    // (or count toward the limit for) actual manual browser logins.
    private const string TEST_IP = '203.0.113.5';

    private AuthService $auth;
    private SettingsRepository $settings;
    private Db $db;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $logger = new Logger(sys_get_temp_dir() . '/auth_service_test.log');
        $this->settings = new SettingsRepository($this->db, $logger);
        $this->auth = new AuthService(
            $this->db,
            new BalleJauneClient('http://example.invalid', '', $logger),
            $logger,
            new AuditLogRepository($this->db),
            $this->settings,
        );
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->db->pdo()->prepare('DELETE FROM settings WHERE name = ?')->execute([self::SETTING_KEY]);
        $this->db->pdo()->prepare('DELETE FROM magic_tokens WHERE email = ?')->execute([self::TEST_EMAIL]);
        $_SESSION = [];
    }

    public function testNoOpWhenNoSession(): void
    {
        $this->auth->clearIfInvalidated();

        self::assertArrayNotHasKey('user', $_SESSION);
    }

    public function testNoOpWhenNoInvalidationHasEverBeenSet(): void
    {
        $_SESSION['user'] = ['login_at' => '2020-01-01 00:00:00'];

        $this->auth->clearIfInvalidated();

        self::assertArrayHasKey('user', $_SESSION);
    }

    public function testKeepsSessionLoggedInAfterTheInvalidationTimestamp(): void
    {
        $this->settings->set(self::SETTING_KEY, '2026-01-01 00:00:00');
        $_SESSION['user'] = ['login_at' => '2026-06-01 00:00:00'];

        $this->auth->clearIfInvalidated();

        self::assertArrayHasKey('user', $_SESSION);
    }

    public function testClearsSessionLoggedInBeforeTheInvalidationTimestamp(): void
    {
        $this->settings->set(self::SETTING_KEY, '2026-06-01 00:00:00');
        $_SESSION['user'] = ['login_at' => '2026-01-01 00:00:00'];

        $this->auth->clearIfInvalidated();

        self::assertArrayNotHasKey('user', $_SESSION);
    }

    public function testConsumeCodeSucceedsWithCorrectCode(): void
    {
        $issued = $this->auth->createToken(self::TEST_EMAIL, 42, self::TEST_IP);
        self::assertNotNull($issued);

        $row = $this->auth->consumeCode(self::TEST_EMAIL, $issued['code']);

        self::assertNotNull($row);
        self::assertSame(42, (int) $row['bj_user_id']);
    }

    public function testConsumeCodeFailsWithWrongCodeAndIncrementsAttempts(): void
    {
        $issued = $this->auth->createToken(self::TEST_EMAIL, 42, self::TEST_IP);
        self::assertNotNull($issued);
        $wrongCode = $issued['code'] === '000000' ? '111111' : '000000';

        $row = $this->auth->consumeCode(self::TEST_EMAIL, $wrongCode);

        self::assertNull($row);
        $stmt = $this->db->pdo()->prepare('SELECT code_attempts, used_at FROM magic_tokens WHERE email = ?');
        $stmt->execute([self::TEST_EMAIL]);
        $stored = $stmt->fetch();
        self::assertSame(1, (int) $stored['code_attempts']);
        self::assertNull($stored['used_at']);
    }

    public function testConsumeCodeLocksOutAfterMaxAttemptsWithoutBurningTheLink(): void
    {
        $issued = $this->auth->createToken(self::TEST_EMAIL, 42, self::TEST_IP);
        self::assertNotNull($issued);
        $wrongCode = $issued['code'] === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            self::assertNull($this->auth->consumeCode(self::TEST_EMAIL, $wrongCode));
        }

        // Locked out even with the right code now...
        self::assertNull($this->auth->consumeCode(self::TEST_EMAIL, $issued['code']));

        // ...but a wrong-code lockout must never burn the still-valid link
        // for that same row — otherwise guessing wrong on purpose would be a
        // way to lock a member out of the link they haven't clicked yet.
        $row = $this->auth->consumeToken($issued['token']);
        self::assertNotNull($row);
    }

    public function testConsumingTheCodeInvalidatesTheLinkToken(): void
    {
        $issued = $this->auth->createToken(self::TEST_EMAIL, 42, self::TEST_IP);
        self::assertNotNull($issued);

        self::assertNotNull($this->auth->consumeCode(self::TEST_EMAIL, $issued['code']));

        self::assertNull($this->auth->consumeToken($issued['token']));
    }

    public function testConsumingTheLinkInvalidatesTheCode(): void
    {
        $issued = $this->auth->createToken(self::TEST_EMAIL, 42, self::TEST_IP);
        self::assertNotNull($issued);

        self::assertNotNull($this->auth->consumeToken($issued['token']));

        self::assertNull($this->auth->consumeCode(self::TEST_EMAIL, $issued['code']));
    }

    public function testConsumeCodeFailsWhenExpired(): void
    {
        $issued = $this->auth->createToken(self::TEST_EMAIL, 42, self::TEST_IP);
        self::assertNotNull($issued);
        $this->db->pdo()
            ->prepare('UPDATE magic_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE email = ?')
            ->execute([self::TEST_EMAIL]);

        self::assertNull($this->auth->consumeCode(self::TEST_EMAIL, $issued['code']));
    }
}
