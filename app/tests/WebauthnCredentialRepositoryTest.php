<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\WebauthnCredentialRepository;
use App\Support\Db;
use PHPUnit\Framework\TestCase;

final class WebauthnCredentialRepositoryTest extends TestCase
{
    private const int BJ_USER_ID = 999999202;
    private const int OTHER_BJ_USER_ID = 999999203;

    private Db $db;
    private WebauthnCredentialRepository $credentials;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->credentials = new WebauthnCredentialRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->pdo()
            ->prepare('DELETE FROM webauthn_credentials WHERE bj_user_id IN (?, ?)')
            ->execute([self::BJ_USER_ID, self::OTHER_BJ_USER_ID]);
    }

    public function testCreateThenFindByCredentialId(): void
    {
        $this->credentials->create(self::BJ_USER_ID, 'cred-abc', '-----BEGIN PUBLIC KEY-----...', 0, 'MacBook Touch ID');

        $row = $this->credentials->findByCredentialId('cred-abc');

        self::assertNotNull($row);
        self::assertSame(self::BJ_USER_ID, (int) $row['bj_user_id']);
        self::assertSame('MacBook Touch ID', $row['label']);
        self::assertSame(0, (int) $row['sign_count']);
        self::assertNull($row['last_used_at']);
    }

    public function testFindByCredentialIdReturnsNullForUnknown(): void
    {
        self::assertNull($this->credentials->findByCredentialId('does-not-exist'));
    }

    public function testForUserOnlyReturnsThatUsersCredentialsNewestFirst(): void
    {
        $this->credentials->create(self::BJ_USER_ID, 'cred-1', 'pem', 0, 'First');
        usleep(1_100_000); // created_at has second precision — force a real ordering gap
        $this->credentials->create(self::BJ_USER_ID, 'cred-2', 'pem', 0, 'Second');
        $this->credentials->create(self::OTHER_BJ_USER_ID, 'cred-3', 'pem', 0, 'Someone else\'s');

        $rows = $this->credentials->forUser(self::BJ_USER_ID);

        self::assertCount(2, $rows);
        self::assertSame('Second', $rows[0]['label']);
        self::assertSame('First', $rows[1]['label']);
    }

    public function testUpdateAfterLoginSetsSignCountAndLastUsedAt(): void
    {
        $this->credentials->create(self::BJ_USER_ID, 'cred-abc', 'pem', 0, 'Label');
        $id = (int) $this->credentials->findByCredentialId('cred-abc')['id'];

        $this->credentials->updateAfterLogin($id, 5);

        $row = $this->credentials->findById($id);
        self::assertSame(5, (int) $row['sign_count']);
        self::assertNotNull($row['last_used_at']);
    }

    public function testDeleteIsScopedToOwner(): void
    {
        $this->credentials->create(self::BJ_USER_ID, 'cred-abc', 'pem', 0, 'Label');
        $id = (int) $this->credentials->findByCredentialId('cred-abc')['id'];

        // Someone else's bj_user_id can't delete it.
        $this->credentials->delete($id, self::OTHER_BJ_USER_ID);
        self::assertNotNull($this->credentials->findById($id));

        $this->credentials->delete($id, self::BJ_USER_ID);
        self::assertNull($this->credentials->findById($id));
    }
}
