<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\PricingFileWriter;
use PHPUnit\Framework\TestCase;

final class PricingFileWriterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pricing_writer_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            array_map('unlink', glob($this->tmpDir . '/*') ?: []);
            rmdir($this->tmpDir);
        }
    }

    public function testWrittenFileLoadsBackToTheSameArray(): void
    {
        $catalogue = require dirname(__DIR__, 2) . '/pricing_data/pricing.2025-2026.php';
        $path = $this->tmpDir . '/pricing.2099-2100.php';

        (new PricingFileWriter())->write($path, $catalogue);

        self::assertFileExists($path);
        self::assertSame($catalogue, require $path);
    }

    public function testCreatesTheTargetDirectoryIfMissing(): void
    {
        self::assertDirectoryDoesNotExist($this->tmpDir);
        (new PricingFileWriter())->write($this->tmpDir . '/pricing.2099-2100.php', ['season_label' => '2099-2100']);
        self::assertFileExists($this->tmpDir . '/pricing.2099-2100.php');
    }

    public function testOverwriteReplacesContentAtomically(): void
    {
        $path = $this->tmpDir . '/pricing.2099-2100.php';
        $writer = new PricingFileWriter();

        $writer->write($path, ['season_label' => 'v1']);
        self::assertSame('v1', (require $path)['season_label']);

        $writer->write($path, ['season_label' => 'v2']);
        self::assertSame('v2', (require $path)['season_label']);

        // No leftover temp files.
        $leftovers = array_filter(glob($this->tmpDir . '/*') ?: [], fn ($f) => str_contains($f, '.tmp'));
        self::assertSame([], $leftovers);
    }

    public function testFloatsRenderWithADecimalPoint(): void
    {
        $path = $this->tmpDir . '/pricing.2099-2100.php';
        (new PricingFileWriter())->write($path, ['price' => 219.0]);

        self::assertStringContainsString('219.0', file_get_contents($path));
    }
}
