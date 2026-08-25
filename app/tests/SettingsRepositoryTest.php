<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\SettingsRepository;
use App\Support\Db;
use App\Support\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: SettingsRepository reads/writes the `settings` table via
 * a real Db connection (dev MySQL — see CLAUDE.md). A dedicated test key is
 * used so this never collides with real settings; tearDown() removes it.
 */
final class SettingsRepositoryTest extends TestCase
{
    private const string TEST_KEY = '__test_setting__';

    private SettingsRepository $settings;
    private Db $db;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->settings = new SettingsRepository($this->db, new Logger(sys_get_temp_dir() . '/settings_repository_test.log'));
    }

    protected function tearDown(): void
    {
        $this->db->pdo()->prepare('DELETE FROM settings WHERE name = ?')->execute([self::TEST_KEY]);
    }

    public function testGetReturnsNullWhenUnset(): void
    {
        self::assertNull($this->settings->get(self::TEST_KEY));
    }

    public function testSetThenGetRoundTrips(): void
    {
        $this->settings->set(self::TEST_KEY, '1');
        self::assertSame('1', $this->settings->get(self::TEST_KEY));

        $this->settings->set(self::TEST_KEY, '0');
        self::assertSame('0', $this->settings->get(self::TEST_KEY));
    }

    public function testIsEnabledTrueOnlyWhenValueIsOne(): void
    {
        self::assertFalse($this->settings->isEnabled(self::TEST_KEY));

        $this->settings->set(self::TEST_KEY, '1');
        self::assertTrue($this->settings->isEnabled(self::TEST_KEY));

        $this->settings->set(self::TEST_KEY, '0');
        self::assertFalse($this->settings->isEnabled(self::TEST_KEY));
    }

    public function testIsEnabledFailsSafeOnDbError(): void
    {
        $brokenDb = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'wrong-password']);
        $settings = new SettingsRepository($brokenDb, new Logger(sys_get_temp_dir() . '/settings_repository_test.log'));

        self::assertFalse($settings->isEnabled(self::TEST_KEY));
    }
}
