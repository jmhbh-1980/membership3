<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Repository\PromoCodeRepository;
use App\Repository\SettingsRepository;
use App\Service\BankDetailsService;
use App\Service\InvoiceDescriptions;
use App\Service\InvoiceLineComposer;
use App\Service\InvoiceNumberService;
use App\Service\InvoicePdfService;
use App\Service\InvoiceService;
use App\Service\OrderBreakdownService;
use App\Service\PricingService;
use App\Support\Db;
use App\Support\Logger;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Cours collectifs and ticket credits bought on their own get an invoice, as a
 * membership does: generated with the same service, listed in the member's
 * "Mes factures". Lessons state the season they run in; credits never expire,
 * so their invoice prints no period.
 *
 * Integration test against the dev MySQL, same convention as CoupleLinksTest.
 * Invoices are issued with a manual number (no counter is touched) and their
 * PDFs written to a throwaway directory.
 */
final class AddOnInvoicesTest extends TestCase
{
    private const int MEMBER = 999999801;

    private Db $db;
    private OrderRepository $orders;
    private InvoiceRepository $invoices;
    private InvoiceService $service;
    private InvoiceLineComposer $composer;
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->orders = new OrderRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
        $this->uploadsDir = sys_get_temp_dir() . '/addon-invoices-' . bin2hex(random_bytes(4));
        mkdir($this->uploadsDir);

        $logger = new Logger(sys_get_temp_dir() . '/addon_invoices_test.log');
        $configDir = dirname(__DIR__, 2) . '/pricing_data';
        $pricing = new PricingService($configDir);
        $this->composer = new InvoiceLineComposer($pricing, new InvoiceDescriptions($configDir));
        $this->service = new InvoiceService(
            $this->invoices,
            new InvoiceNumberService($this->db),
            new OrderBreakdownService(new PromoCodeRepository($this->db)),
            $this->composer,
            new InvoicePdfService(
                $this->uploadsDir,
                ['name' => 'Bad & Squash La Garenne-Colombes', 'address' => '68 boulevard de la République', 'postal_city' => '92250 La Garenne-Colombes',
                    'siret' => '0', 'email' => 'test@example.com', 'phone' => '0', 'website' => 'example.com'],
                dirname(__DIR__) . '/assets/logo.png',
                new BankDetailsService(new SettingsRepository($this->db, $logger)),
            ),
            $this->uploadsDir,
            $logger,
        );
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        array_map('unlink', glob($this->uploadsDir . '/invoices/*/*') ?: []);
        array_map('rmdir', glob($this->uploadsDir . '/invoices/*') ?: []);
        @rmdir($this->uploadsDir . '/invoices');
        @rmdir($this->uploadsDir);
    }

    private function cleanUp(): void
    {
        $this->db->pdo()->prepare('DELETE i FROM invoices i JOIN orders o ON o.id = i.order_id WHERE o.bj_user_id = ?')->execute([self::MEMBER]);
        $this->db->pdo()->prepare('DELETE FROM orders WHERE bj_user_id = ?')->execute([self::MEMBER]);
    }

    private const array BILLED = ['firstname' => 'Test', 'lastname' => 'FACTURE', 'address' => '1 rue du Test', 'postalcode' => '92250', 'city' => 'La Garenne-Colombes'];

    public function testALessonsOrderIsInvoicedForItsSeason(): void
    {
        $order = $this->orders->create('lessons', null, self::MEMBER, 'addon-invoice-test@example.com', 120.0,
            [['type' => 'lessons', 'label' => 'Cours collectifs (adultes)', 'amount' => 120.0, 'baseAmount' => 120.0]],
            ['seasonStartYear' => 2026]);
        self::assertTrue($this->service->isEligible($order));

        $context = InvoiceService::addOnContext($order, self::BILLED);
        self::assertSame(2026, $context['season']->startYear);
        self::assertSame('Test FACTURE', $context['billingName']);

        $rows = $this->composer->compose(['lines' => json_decode($order['cart_lines'], true)], $context);
        self::assertSame([['description' => 'Cours collectifs (adultes)', 'blurb' => '', 'quantity' => 1, 'unitPrice' => 120.0, 'reduc' => '0', 'amount' => 120.0]], $rows);

        $invoice = $this->service->generateForOrder($order, $context, new DateTimeImmutable('2099-09-01'), 'TEST-ADDON-LESSONS');
        self::assertNotNull($invoice);
        self::assertFileExists($this->uploadsDir . '/' . $invoice['pdf_path']);
        self::assertSame([(int) $invoice['id']], array_map(fn (array $i) => (int) $i['id'], $this->invoices->findForBjUser(self::MEMBER)));
    }

    public function testACreditsOrderIsInvoicedWithoutAPeriod(): void
    {
        $order = $this->orders->create('credits', null, self::MEMBER, 'addon-invoice-test@example.com', 10.0,
            [['type' => 'tickets', 'label' => 'Formule Tickets — 5 séances', 'amount' => 10.0]],
            ['tickets' => 5]);

        $context = InvoiceService::addOnContext($order, self::BILLED);
        self::assertNull($context['season']);

        $invoice = $this->service->generateForOrder($order, $context, new DateTimeImmutable('2099-09-01'), 'TEST-ADDON-CREDITS');
        self::assertNotNull($invoice);
        self::assertSame(10.0, (float) $invoice['amount']);
        self::assertCount(1, $this->invoices->findForBjUser(self::MEMBER));
    }

    public function testOrdersFromBeforeInvoicingStayWithoutOne(): void
    {
        foreach (['lessons', 'credits'] as $kind) {
            self::assertFalse($this->service->isEligible(['kind' => $kind, 'created_at' => '2026-08-20 10:00:00']));
            self::assertTrue($this->service->isEligible(['kind' => $kind, 'created_at' => '2026-09-20 10:00:00']));
        }
    }
}
