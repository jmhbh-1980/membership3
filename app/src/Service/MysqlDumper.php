<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Pure-PHP equivalent of `mysqldump`'s default text output — no shell_exec()
 * involved, since the web-facing PHP-FPM pool this runs under (unlike the
 * SSH shell's CLI php) has that disabled; see BackupService, the only
 * caller. Structurally the same shape mysqldump produces: DROP+CREATE per
 * table (so a restore doesn't care whether the target already has the
 * schema), then batched multi-row INSERTs, the whole thing wrapped in
 * SET FOREIGN_KEY_CHECKS = 0/1 so table order during restore doesn't matter
 * even though this schema has several cross-table FKs (documents →
 * applications, invoices → orders, credit_notes → invoices...).
 */
final class MysqlDumper
{
    private const int BATCH_SIZE = 200;

    public function dump(PDO $pdo): string
    {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        $sql = '-- Dump généré le ' . date('Y-m-d H:i:s') . "\n"
            . "SET NAMES utf8mb4;\n"
            . "SET FOREIGN_KEY_CHECKS = 0;\n";

        foreach ($tables as $table) {
            $sql .= $this->dumpTable($pdo, (string) $table);
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        return $sql;
    }

    private function dumpTable(PDO $pdo, string $table): string
    {
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC);

        $sql = "\n-- Table `{$table}`\n"
            . "DROP TABLE IF EXISTS `{$table}`;\n"
            . $create['Create Table'] . ";\n";

        $stmt = $pdo->query('SELECT * FROM `' . $table . '`');
        $columns = null;
        $batch = [];

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $columns ??= array_keys($row);
            $batch[] = '(' . implode(',', array_map(
                static fn ($value) => $value === null ? 'NULL' : $pdo->quote((string) $value),
                $row,
            )) . ')';

            if (count($batch) >= self::BATCH_SIZE) {
                $sql .= $this->insertStatement($table, $columns, $batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $sql .= $this->insertStatement($table, $columns, $batch);
        }

        return $sql;
    }

    /**
     * @param string[] $columns
     * @param string[] $rows
     */
    private function insertStatement(string $table, array $columns, array $rows): string
    {
        $columnList = '`' . implode('`,`', $columns) . '`';

        return "INSERT INTO `{$table}` ({$columnList}) VALUES\n" . implode(",\n", $rows) . ";\n";
    }
}
