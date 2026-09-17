<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\MysqlDumper;
use App\Support\Db;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test against the dev MySQL. dump() covers every table in the
 * connected schema, so this uses a uniquely-named throwaway table in the
 * shared `membership` database (same convention as other repository tests)
 * rather than a whole separate database — the `membership` user has no
 * CREATE DATABASE privilege, only rights within that one schema.
 *
 * The round-trip test replays only *this table's own section* of the dump
 * (extracted from the full output, same as a restore tool would let you
 * replay a single table) rather than the whole dump — replaying the whole
 * thing would DROP and recreate every real table in the shared schema,
 * which every other test also depends on.
 */
final class MysqlDumperTest extends TestCase
{
    private const string TABLE = '_mysqldumper_test_fixture';

    private Db $db;
    private MysqlDumper $dumper;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->dumper = new MysqlDumper();

        $this->db->pdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->db->pdo()->exec(
            'CREATE TABLE ' . self::TABLE . ' (
                id    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                label VARCHAR(100) NOT NULL,
                note  VARCHAR(200) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        $this->db->pdo()->exec(
            'INSERT INTO ' . self::TABLE . " (label, note) VALUES
             ('Café d''été', NULL),
             ('Simple \"quoted\" name', 'a note with a backslash \\\\')",
        );
    }

    protected function tearDown(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    public function testDumpContainsSchemaAndData(): void
    {
        $sql = $this->dumper->dump($this->db->pdo());

        self::assertStringContainsString('DROP TABLE IF EXISTS `' . self::TABLE . '`;', $sql);
        self::assertStringContainsString('CREATE TABLE `' . self::TABLE . '`', $sql);
        self::assertStringContainsString('INSERT INTO `' . self::TABLE . '`', $sql);
        self::assertStringContainsString('SET FOREIGN_KEY_CHECKS = 0;', $sql);
        self::assertStringContainsString('SET FOREIGN_KEY_CHECKS = 1;', $sql);
    }

    /** The point of a backup is that restoring it works — so prove the round trip, not just the text shape. */
    public function testDumpRestoresExactlyWhenReplayed(): void
    {
        $sql = $this->dumper->dump($this->db->pdo());
        $tableSql = $this->extractTableSection($sql, self::TABLE);

        $this->db->pdo()->exec('DROP TABLE ' . self::TABLE);
        $this->db->pdo()->exec($tableSql);

        $rows = $this->db->pdo()
            ->query('SELECT id, label, note FROM ' . self::TABLE . ' ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame([
            ['id' => 1, 'label' => "Café d'été", 'note' => null],
            ['id' => 2, 'label' => 'Simple "quoted" name', 'note' => 'a note with a backslash \\'],
        ], $rows);
    }

    public function testDumpPreservesAutoIncrementCounter(): void
    {
        $sql = $this->dumper->dump($this->db->pdo());
        $tableSql = $this->extractTableSection($sql, self::TABLE);

        $this->db->pdo()->exec('DROP TABLE ' . self::TABLE);
        $this->db->pdo()->exec($tableSql);

        $this->db->pdo()->exec('INSERT INTO ' . self::TABLE . " (label) VALUES ('third')");
        $nextId = (int) $this->db->pdo()->lastInsertId();

        self::assertSame(3, $nextId);
    }

    /** Pulls just one table's own "-- Table `x`" ... section out of a full multi-table dump. */
    private function extractTableSection(string $fullDump, string $table): string
    {
        $marker = "\n-- Table `{$table}`\n";
        $start = strpos($fullDump, $marker);
        self::assertNotFalse($start, 'dump did not contain a section for ' . $table);

        $nextMarkerPos = strpos($fullDump, "\n-- Table `", $start + strlen($marker));
        $end = $nextMarkerPos !== false ? $nextMarkerPos : strpos($fullDump, "\nSET FOREIGN_KEY_CHECKS = 1;", $start);
        self::assertNotFalse($end);

        return substr($fullDump, $start, $end - $start);
    }
}
