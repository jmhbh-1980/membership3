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
}
