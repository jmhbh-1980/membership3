<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\SettingsRepository;
use App\Service\ReglementInterieurService;
use App\Support\Db;
use App\Support\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: real Db connection (dev MySQL, see CLAUDE.md).
 * tearDown() clears the settings row.
 */
final class ReglementInterieurServiceTest extends TestCase
{
    private ReglementInterieurService $reglement;
    private Db $db;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $settings = new SettingsRepository($this->db, new Logger(sys_get_temp_dir() . '/reglement_interieur_test.log'));
        $this->reglement = new ReglementInterieurService($settings);
        // The real key is seeded by a migration (0021) — start each test from
        // a clean slate rather than whatever the seed or a prior test left.
        $this->db->pdo()->prepare('DELETE FROM settings WHERE name = ?')->execute(['reglement_interieur']);
    }

    protected function tearDown(): void
    {
        $this->db->pdo()->prepare('DELETE FROM settings WHERE name = ?')->execute(['reglement_interieur']);
    }

    public function testUnsetIsEmptyEverywhere(): void
    {
        self::assertSame('', $this->reglement->markdown());
        self::assertSame('', $this->reglement->html());
    }

    public function testSaveThenMarkdownRoundTrips(): void
    {
        $this->reglement->save("# Règlement\n\nSemelles non marquantes obligatoires.");

        self::assertSame("# Règlement\n\nSemelles non marquantes obligatoires.", $this->reglement->markdown());
    }

    public function testHtmlRendersMarkdown(): void
    {
        $this->reglement->save('## Chaussures' . "\n\n" . '1. Semelles non marquantes');

        $html = $this->reglement->html();

        self::assertStringContainsString('<h2>Chaussures</h2>', $html);
        self::assertStringContainsString('<li>Semelles non marquantes</li>', $html);
    }

    public function testRawHtmlInSourceIsEscapedNotRendered(): void
    {
        $this->reglement->save('<script>alert(1)</script>');

        $html = $this->reglement->html();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
