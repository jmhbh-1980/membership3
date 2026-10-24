<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\AuditLogRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Repository\PromoCodeRepository;
use App\Repository\ResidenceExceptionRepository;
use App\Repository\SettingsRepository;
use App\Service\BankDetailsService;
use App\Service\InvoiceDescriptions;
use App\Service\InvoiceLineComposer;
use App\Service\InvoiceNumberService;
use App\Service\InvoicePdfService;
use App\Service\InvoiceService;
use App\Service\ManualOrderService;
use App\Service\OrderBreakdownService;
use App\Service\PricingService;
use App\Service\RenewalService;
use App\Service\Season;
use App\Support\Db;
use App\Support\Logger;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A payment taken outside the app, recorded by an admin: the order, what it
 * entitles the member to here (formula, lessons), its invoice — and its
 * withdrawal, allowed only while its invoice is the latest number issued.
 *
 * Integration test against the dev MySQL, same convention as CoupleLinksTest.
 * Invoices are issued in 2099 so the only counter touched is the 2099-2100
 * one, deleted afterwards, and their PDFs go to a throwaway directory.
 */
final class ManualOrderServiceTest extends TestCase
{
    private const int MEMBER = 999999901;
    private const int PARTNER = 999999902;
    private const int OTHER = 999999903;
    private const string ADMIN = 'manual-order-test@example.com';
    private const string TEST_COUNTER = '2099-2100';

    private Db $db;
    private OrderRepository $orders;
    private InvoiceRepository $invoices;
    private InvoiceLineComposer $composer;
    private ManualOrderService $service;
    private Season $season;
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->orders = new OrderRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
        $this->uploadsDir = sys_get_temp_dir() . '/manual-orders-' . bin2hex(random_bytes(4));
        mkdir($this->uploadsDir);
        $this->season = new Season(2026);

        $logger = new Logger(sys_get_temp_dir() . '/manual_order_test.log');
        $configDir = dirname(__DIR__, 2) . '/pricing_data';
        $pricing = new PricingService($configDir);
        $this->composer = new InvoiceLineComposer($pricing, new InvoiceDescriptions($configDir));
        $invoiceService = new InvoiceService(
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
        $this->service = new ManualOrderService(
            $this->db,
            $pricing,
            $this->orders,
            new RenewalService($this->db, $pricing),
            new ResidenceExceptionRepository($this->db),
            $this->invoices,
            $invoiceService,
            new AuditLogRepository($this->db),
            $this->uploadsDir,
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
        $pdo = $this->db->pdo();
        $ids = implode(',', [self::MEMBER, self::PARTNER, self::OTHER]);
        $pdo->exec("DELETE i FROM invoices i JOIN orders o ON o.id = i.order_id WHERE o.bj_user_id IN ({$ids})");
        $pdo->exec("DELETE FROM lesson_enrollments WHERE bj_user_id IN ({$ids})");
        $pdo->exec("DELETE FROM member_formulas WHERE bj_user_id IN ({$ids})");
        $pdo->exec("DELETE FROM orders WHERE bj_user_id IN ({$ids})");
        $pdo->prepare('DELETE FROM invoice_counters WHERE season_label = ?')->execute([self::TEST_COUNTER]);
        $pdo->prepare('DELETE FROM audit_log WHERE actor = ?')->execute([self::ADMIN]);
    }

    private static function user(int $id, string $firstname = 'Test'): array
    {
        return [
            'user_id' => $id, 'firstname' => $firstname, 'lastname' => 'MANUEL', 'email' => "manual-{$id}@example.com",
            'address' => '1 rue du Test', 'postalcode' => '92000', 'city' => 'Nanterre',
        ];
    }

    private function membership(array $overrides = []): array
    {
        return $overrides + [
            'purchase' => ManualOrderService::MEMBERSHIP, 'season' => $this->season,
            'effectiveDate' => new DateTimeImmutable('2026-09-01'), 'subscriptionType' => 'heures-pleines',
            'premiere' => false, 'competitor' => true, 'lessons' => 1,
        ];
    }

    private function create(array $input, array $amounts = [], int $member = self::MEMBER, ?array $partner = null): array
    {
        return $this->service->create(
            self::user($member),
            $partner,
            $input,
            $amounts,
            ['date' => new DateTimeImmutable('2026-09-01'), 'method' => 'coupons-sport', 'note' => '200 € de coupons'],
            self::ADMIN,
            ['issuedAt' => new DateTimeImmutable('2099-09-01')],
        );
    }

    private function formula(int $bjUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM member_formulas WHERE season_start_year = 2026 AND bj_user_id = ?');
        $stmt->execute([$bjUserId]);
        return $stmt->fetch() ?: null;
    }

    private function lessonEnrolments(int $bjUserId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM lesson_enrollments WHERE season_start_year = 2026 AND bj_user_id = ?');
        $stmt->execute([$bjUserId]);
        return (int) $stmt->fetchColumn();
    }

    public function testRecordsASoloMembershipWithItsFormulaLessonsAndInvoice(): void
    {
        $result = $this->create($this->membership());
        $order = $result['order'];
        $meta = json_decode($order['meta'], true);

        // Hors-commune Heures Pleines renewal 283 € + licence fédérale 55 € + cours collectifs 120 €.
        self::assertSame(['renewal', 'manual', 'fulfilled', '458.00'], [$order['kind'], $order['payment_method'], $order['status'], $order['amount']]);
        self::assertSame(['Coupons Sport', '2026-09-01', '200 € de coupons'], [$meta['actualPaymentMethod'], $meta['actualPaymentDate'], $meta['actualPaymentNote']]);
        self::assertFalse($meta['amountsEdited']);
        self::assertSame([1, 1, (int) $order['id']], array_map('intval', array_values(array_intersect_key(
            $this->formula(self::MEMBER), ['competitor' => 0, 'lessons' => 0, 'order_id' => 0]))));
        self::assertSame(1, $this->lessonEnrolments(self::MEMBER));
        self::assertSame('SQ-' . self::TEST_COUNTER . '-001', $result['invoice']['number']);
        self::assertSame(458.0, (float) $result['invoice']['amount']);
        self::assertFileExists($this->uploadsDir . '/' . $result['invoice']['pdf_path']);
    }

    public function testACoupleRecordsBothFormulasLinkedToEachOther(): void
    {
        $order = $this->create($this->membership(['isCouple' => true, 'competitor' => false, 'lessons' => 2]), [], self::MEMBER, self::user(self::PARTNER, 'Partner'))['order'];

        self::assertSame(self::PARTNER, (int) $this->formula(self::MEMBER)['partner_bj_user_id']);
        self::assertSame(self::MEMBER, (int) $this->formula(self::PARTNER)['partner_bj_user_id']);
        self::assertSame((int) $order['id'], (int) $this->formula(self::PARTNER)['order_id']);
        self::assertSame(1, $this->lessonEnrolments(self::PARTNER));
    }

    public function testEditedAmountsMakeTheTotalAndARaisedLineItsOwnBase(): void
    {
        $order = $this->create($this->membership(), ['199,00', '', '130'])['order'];
        $lines = json_decode($order['cart_lines'], true);
        $meta = json_decode($order['meta'], true);

        self::assertSame([199.0, 283.0], [(float) $lines[0]['amount'], (float) $lines[0]['baseAmount']]); // lowered: invoiced as a reduction
        self::assertSame([55.0, 55.0], [(float) $lines[1]['amount'], (float) $lines[1]['baseAmount']]);
        self::assertSame([130.0, 130.0], [(float) $lines[2]['amount'], (float) $lines[2]['baseAmount']]); // raised: no negative reduction
        self::assertSame('384.00', $order['amount']);
        self::assertTrue($meta['amountsEdited']);
        self::assertSame(283.0, (float) $meta['gridLines'][0]['amount']);
    }

    public function testAmountsAreValidated(): void
    {
        $grid = [['label' => 'Cotisation', 'amount' => 10.0, 'baseAmount' => 10.0]];
        self::assertSame(12.5, ManualOrderService::applyAmounts($grid, ['12,50'])[0]['amount']);

        foreach ([['abc'], ['-1'], ['0']] as $amounts) {
            try {
                ManualOrderService::applyAmounts($grid, $amounts);
                self::fail('Accepted ' . $amounts[0]);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRefusesASecondMembershipForTheSameSeason(): void
    {
        $first = $this->create($this->membership())['order'];

        $refusal = $this->service->refusal(self::MEMBER, $this->membership());
        self::assertSame((int) $first['id'], $refusal['orderId']);
        $this->expectException(InvalidArgumentException::class);
        $this->create($this->membership());
    }

    public function testLessonsAloneEnrolOnceAndAreUndoneOnDelete(): void
    {
        (new RenewalService($this->db, new PricingService(dirname(__DIR__, 2) . '/pricing_data')))
            ->recordFormula(2026, self::MEMBER, 'heures-pleines', false, false, 0, 0, null);
        $input = ['purchase' => ManualOrderService::LESSONS, 'season' => $this->season, 'effectiveDate' => new DateTimeImmutable('2026-09-10')];

        $order = $this->create($input)['order'];
        self::assertSame(['lessons', '120.00'], [$order['kind'], $order['amount']]);
        self::assertSame(1, (int) $this->formula(self::MEMBER)['lessons']);
        self::assertNotNull($this->service->refusal(self::MEMBER, $input));

        $this->service->delete($this->orders->findById((int) $order['id']), self::ADMIN);
        self::assertSame(0, (int) $this->formula(self::MEMBER)['lessons']);
        self::assertSame(0, $this->lessonEnrolments(self::MEMBER));
        self::assertNull($this->service->refusal(self::MEMBER, $input));
    }

    public function testCreditsCountThePacks(): void
    {
        $order = $this->create(['purchase' => ManualOrderService::CREDITS, 'season' => $this->season,
            'effectiveDate' => new DateTimeImmutable('2026-09-01'), 'packs' => 2])['order'];
        $lines = json_decode($order['cart_lines'], true);
        $meta = json_decode($order['meta'], true);
        $pack = (new PricingService(dirname(__DIR__, 2) . '/pricing_data'))->ticketPack($this->season);

        self::assertSame('credits', $order['kind']);
        self::assertStringEndsWith(' × 2', $lines[0]['label']);
        self::assertSame(round($pack['price'] * 2, 2), (float) $order['amount']);
        self::assertSame($pack['tickets'] * 2, $meta['tickets']);
        $row = $this->composer->compose(['lines' => $lines], ['season' => null])[0];
        self::assertSame([2, (float) $pack['price']], [$row['quantity'], $row['unitPrice']]);
    }

    public function testOnlyTheLatestInvoiceCanBeWithdrawn(): void
    {
        $earlier = $this->create($this->membership())['order'];
        $later = $this->create($this->membership(), [], self::OTHER)['order'];
        self::assertFalse($this->service->deletable($earlier));
        self::assertTrue($this->service->deletable($later));

        $laterInvoice = $this->invoices->findByOrderId((int) $later['id']);
        $this->service->delete($later, self::ADMIN);

        self::assertNull($this->orders->findById((int) $later['id']));
        self::assertNull($this->invoices->findByOrderId((int) $later['id']));
        self::assertNull($this->formula(self::OTHER));
        self::assertFileDoesNotExist($this->uploadsDir . '/' . $laterInvoice['pdf_path']);
        self::assertTrue($this->service->deletable($earlier));
        // The number went back to the counter: the next invoice reuses it.
        $again = $this->create($this->membership(), [], self::OTHER);
        self::assertSame($laterInvoice['number'], $again['invoice']['number']);

        $this->expectException(RuntimeException::class);
        $this->service->delete($earlier, self::ADMIN);
    }

    public function testAnOnlineOrderIsNeverDeletableHere(): void
    {
        self::assertFalse($this->service->deletable(['id' => 1, 'payment_method' => 'online']));
    }
}
