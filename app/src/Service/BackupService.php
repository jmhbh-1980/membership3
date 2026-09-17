<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Db;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * On-demand backups for /admin/sauvegardes: a full database dump (via
 * MysqlDumper) plus uploads/ and pricing_data/ — both gitignored and
 * otherwise unrecoverable, see DEPLOY.md's Sauvegardes section — zipped into
 * one file under $backupsDir. Admin-triggered rather than scheduled: the
 * season's activity is bursty (most of it lands in the first two weeks of
 * September), so a button an admin reaches for before/after a risky
 * operation is more useful here than a blind nightly cron, and this app has
 * no job scheduler to run one anyway.
 *
 * Nothing here ever prunes old backups — that's a deliberate omission for
 * now, not an oversight; see the class's admin controller for the current
 * manual-download-then-delete-if-you-want story.
 */
final class BackupService
{
    private const string FILENAME_PATTERN = '/^backup-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/';

    public function __construct(
        private readonly Db $db,
        private readonly MysqlDumper $dumper,
        private readonly string $backupsDir,
        private readonly string $uploadsDir,
        private readonly string $pricingDataDir,
    ) {
    }

    /** @return list<array{filename: string, size: int, created_at: int}> newest first */
    public function list(): array
    {
        if (!is_dir($this->backupsDir)) {
            return [];
        }

        $files = glob($this->backupsDir . '/backup-*.zip') ?: [];
        $backups = array_map(static fn (string $path) => [
            'filename'   => basename($path),
            'size'       => filesize($path) ?: 0,
            'created_at' => filemtime($path) ?: 0,
        ], $files);

        usort($backups, static fn (array $a, array $b) => $b['created_at'] <=> $a['created_at']);

        return $backups;
    }

    /** @return array{filename: string, size: int} */
    public function generate(): array
    {
        if (!is_dir($this->backupsDir) && !mkdir($this->backupsDir, 0775, true) && !is_dir($this->backupsDir)) {
            throw new RuntimeException('Impossible de créer le dossier de sauvegardes.');
        }

        // Zipping uploads/ only gets slower as the season fills it up — the web
        // server's default execution-time limit is comfortably short for that.
        set_time_limit(300);

        $filename = 'backup-' . date('Y-m-d-His') . '.zip';
        $path = $this->backupsDir . '/' . $filename;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Impossible de créer l'archive de sauvegarde.");
        }

        $zip->addFromString('database.sql', $this->dumper->dump($this->db->pdo()));
        $this->addDirectory($zip, $this->uploadsDir, 'uploads');
        $this->addDirectory($zip, $this->pricingDataDir, 'pricing_data');

        $zip->close();

        return ['filename' => $filename, 'size' => filesize($path) ?: 0];
    }

    /**
     * Resolves a listed backup's filesystem path, or null for anything that
     * isn't one — the strict filename pattern is the allowlist (same idea as
     * the other admin-gated document routes), not just basename().
     */
    public function pathFor(string $filename): ?string
    {
        if (preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            return null;
        }

        $path = $this->backupsDir . '/' . $filename;

        return is_file($path) ? $path : null;
    }

    private function addDirectory(ZipArchive $zip, string $dir, string $zipPrefix): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($dir) + 1);
            $zip->addFile($file->getPathname(), $zipPrefix . '/' . $relative);
        }
    }
}
