<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * bin/charge-installments.php hand-wires 28 services that the web app gets from
 * PHP-DI autowiring, so a constructor gaining a parameter breaks this one script
 * and nothing else — which is exactly what happened when FulfillmentService
 * gained ResidenceExceptionRepository: the script fatalled on startup, and since
 * nothing exercised it, installments 2 and 3 stopped being charged silently.
 *
 * Every `new App\...` in the script sits in the header block above the loop, so
 * reaching the summary line proves the whole graph was built — PHP throws on a
 * wrong argument count and on a wrong type, and none of the hand-wired
 * constructors have two parameters of the same type for a swap to hide in.
 *
 * Integration test against the real dev DB (see CLAUDE.md), like the repository
 * tests. --dry-run reads the schedule and stops before every write, SumUp charge
 * and Balle Jaune request, so this touches nothing.
 */
final class ChargeInstallmentsCliTest extends TestCase
{
    public function testDryRunBuildsTheWholeDependencyGraph(): void
    {
        $script = dirname(__DIR__) . '/bin/charge-installments.php';
        self::assertFileExists($script);

        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --dry-run 2>&1',
            $output,
            $exitCode,
        );
        $printed = implode("\n", $output);

        self::assertSame(0, $exitCode, "charge-installments.php --dry-run failed:\n{$printed}");
        // Reaching the summary is what proves it got past the wiring block,
        // rather than exiting early for some other reason.
        self::assertStringContainsString('échéance(s) examinée(s)', $printed);
        self::assertStringContainsString('dry-run, rien exécuté', $printed);
    }

    /**
     * A PHP notice or deprecation here would be invisible in production (cron
     * output goes nowhere) but still signals wiring rot — a service constructed
     * with a deprecated signature, say.
     */
    public function testDryRunEmitsNoPhpDiagnostics(): void
    {
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/charge-installments.php')
            . ' --dry-run 2>&1',
            $output,
        );
        $printed = implode("\n", $output);

        foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'ArgumentCountError', 'TypeError'] as $marker) {
            self::assertStringNotContainsString($marker, $printed, "unexpected PHP diagnostic:\n{$printed}");
        }
    }
}
