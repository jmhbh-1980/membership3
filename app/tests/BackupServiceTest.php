<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\BackupService;
use App\Service\MysqlDumper;
use App\Support\Db;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Integration test against the dev MySQL (read-only from BackupService's
 * side — dump() only SELECTs/SHOWs, never mutates — so this runs safely
 * against the shared `membership` schema every other test also uses).
 */
final class BackupServiceTest extends TestCase
{
    private string $root;
    private BackupService $backups;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/backup_service_test_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/backups', 0775, true);
        mkdir($this->root . '/uploads/applications/1', 0775, true);
        mkdir($this->root . '/pricing_data', 0775, true);

        file_put_contents($this->root . '/uploads/applications/1/photo.jpg', 'fake-jpeg-bytes');
        file_put_contents($this->root . '/pricing_data/pricing.2026-2027.php', "<?php return [];\n");

        $db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->backups = new BackupService(
            $db,
            new MysqlDumper(),
            $this->root . '/backups',
            $this->root . '/uploads',
            $this->root . '/pricing_data',
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testListIsEmptyBeforeAnyBackup(): void
    {
        self::assertSame([], $this->backups->list());
    }

    public function testGenerateProducesADownloadableZipContainingEverything(): void
    {
        $result = $this->backups->generate();

        self::assertMatchesRegularExpression('/^backup-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/', $result['filename']);
        self::assertGreaterThan(0, $result['size']);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->root . '/backups/' . $result['filename']) === true);
        self::assertNotFalse($zip->locateName('database.sql'));
        self::assertNotFalse($zip->locateName('uploads/applications/1/photo.jpg'));
        self::assertNotFalse($zip->locateName('pricing_data/pricing.2026-2027.php'));
        $zip->close();
    }

    public function testGeneratedBackupShowsUpInList(): void
    {
        $result = $this->backups->generate();

        $list = $this->backups->list();
        self::assertCount(1, $list);
        self::assertSame($result['filename'], $list[0]['filename']);
        self::assertSame($result['size'], $list[0]['size']);
    }

    public function testPathForResolvesAGeneratedBackup(): void
    {
        $result = $this->backups->generate();

        self::assertSame(
            $this->root . '/backups/' . $result['filename'],
            $this->backups->pathFor($result['filename']),
        );
    }

    #[DataProvider('rejectedFilenames')]
    public function testPathForRejectsAnythingNotMatchingTheAllowlist(string $filename): void
    {
        self::assertNull($this->backups->pathFor($filename));
    }

    public static function rejectedFilenames(): array
    {
        return [
            'path traversal'       => ['../../../etc/passwd'],
            'wrong extension'      => ['backup-2026-09-17-120000.sql'],
            'no timestamp'         => ['backup-evil.zip'],
            'well-formed but absent' => ['backup-2000-01-01-000000.zip'],
        ];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
