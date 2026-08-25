<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;
use App\Support\Logger;

/**
 * Generic key-value app settings (table `settings`, unused until this).
 */
class SettingsRepository
{
    public function __construct(
        private readonly Db $db,
        private readonly Logger $logger,
    ) {
    }

    public function get(string $name): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT value FROM settings WHERE name = ?');
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function set(string $name, string $value): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO settings (name, value, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()'
        );
        $stmt->execute([$name, $value]);
    }

    /**
     * Fail-safe boolean read — called on every page render (see PhpRenderer
     * factory in bootstrap.php), so a DB hiccup must hide the feature, not
     * break every page.
     */
    public function isEnabled(string $name): bool
    {
        try {
            return $this->get($name) === '1';
        } catch (\Throwable $e) {
            $this->logger->error('settings', 'Lecture impossible, repli désactivé', ['name' => $name, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
