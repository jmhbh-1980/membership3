<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\ApplicationRepository;
use App\Repository\CreditNoteRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Repository\PromoCodeRepository;
use App\Service\CreditNoteService;
use App\Service\InvoiceNumberService;
use App\Service\PricingService;
use App\Support\Db;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for the avoir assessment — the delta computation and, more
 * importantly, the guard that refuses to credit an amount it cannot prove.
 *
 * assess() never calls Balle Jaune or generates a PDF (only issue() does), so
 * this exercises the real service with real repositories against dev MySQL and
 * no external calls. Order/invoice rows are fabricated for a bj_user_id far
 * outside any real range and removed in tearDown().
 */
final class CreditNoteServiceTest extends TestCase
{
    private const int SEASON = 2026;
    private const int FAKE_BJ_USER_ID = 999999201;

    private CreditNoteService $service;
    private OrderRepository $orders;
    private Db $db;
    private array $orderIds = [];

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $this->orders = new OrderRepository($this->db);
        $pricing = new PricingService(dirname(__DIR__, 2) . '/pricing_data');

        // Real instances rather than doubles (these classes are final), pointed
        // at inert paths and an empty BJ base URL: assess() never reaches the PDF
        // writer or Balle Jaune, so a call that did would fail loudly instead of
        // quietly hitting the network.
        $logger = new \App\Support\Logger(sys_get_temp_dir() . '/credit_note_service_test.log');

        $this->service = new CreditNoteService(
            new CreditNoteRepository($this->db),
            new InvoiceRepository($this->db),
            $this->orders,
            new ApplicationRepository($this->db),
            new PromoCodeRepository($this->db),
            new InvoiceNumberService($this->db),
            $pricing,
            new \App\Service\InvoicePdfService(
                sys_get_temp_dir(),
                [],
                '',
                new \App\Service\BankDetailsService(new \App\Repository\SettingsRepository($this->db, $logger), []),
            ),
            new \App\Service\BalleJaune\BalleJauneClient('', '', $logger),
            $logger,
            sys_get_temp_dir(),
        );
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->pdo();
        foreach ($this->orderIds as $id) {
            $pdo->prepare('DELETE FROM credit_notes WHERE order_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE order_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM member_formulas WHERE order_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
        }
    }

    /**
     * A renewal order settled at the hors-commune grid, with an invoice, exactly
     * as it would look the moment an admin decides to grant the exception.
     */
    private function fulfilledRenewal(float $amount, array $metaOverrides = [], bool $withInvoice = true): array
    {
        $meta = [
            'subscriptionType' => 'heures-pleines',
            'isCouple'         => false,
            'competitor'       => false,
            'lessons'          => 0,
            'seasonStartYear'  => self::SEASON,
            'residence'        => PricingService::RESIDENCE_HORS_COMMUNE,
            'pricingResidence' => PricingService::RESIDENCE_HORS_COMMUNE,
            'licenceRemoved'   => false,
            'lateSettlement'   => false,
        ] + $metaOverrides;

        $order = $this->orders->create(
            'renewal',
            null,
            self::FAKE_BJ_USER_ID,
            'test@example.org',
            $amount,
            [],
            $meta,
            residence: PricingService::RESIDENCE_HORS_COMMUNE,
            pricingResidence: PricingService::RESIDENCE_HORS_COMMUNE,
        );
        $id = (int) $order['id'];
        $this->orderIds[] = $id;

        // Paid on 1 September, so the prorata factor is 1 and the numbers below
        // are the plain catalogue ones.
        $this->db->pdo()
            ->prepare('UPDATE orders SET status = "fulfilled", created_at = ?, fulfilled_at = ? WHERE id = ?')
            ->execute([self::SEASON . '-09-01 10:00:00', self::SEASON . '-09-01 10:00:00', $id]);

        if ($withInvoice) {
            $this->db->pdo()->prepare(
                'INSERT INTO invoices (order_id, number, season_label, sequence, amount, pdf_path, issued_at, created_at)
                 VALUES (?, ?, ?, ?, ?, "", NOW(), NOW())'
            )->execute([$id, 'TEST-' . $id, self::SEASON . '-' . (self::SEASON + 1), 0, $amount]);
        }

        return $this->orders->findById($id);
    }

    public function testCreditsTheDifferenceBetweenTheTwoGrids(): void
    {
        // 283 (hors-commune renouvellement) + 20 (pass) = 303 paid.
        $order = $this->fulfilledRenewal(303.0);

        $assessment = $this->service->assess($order, PricingService::RESIDENCE_GARENNOIS);

        self::assertTrue($assessment['eligible'], $assessment['problem'] ?? '');
        // At the Garennois grid: 199 + 20 = 219. The licence is never residence-priced.
        self::assertSame(84.0, $assessment['amount']);
    }

    public function testTheDiscountFollowsTheGridRatherThanBeingAFlatPriceDifference(): void
    {
        // 50% student discount off cotisation only: (283 * 0.5) + 20 = 161.50 paid.
        $order = $this->fulfilledRenewal(161.5);
        $this->db->pdo()->prepare('UPDATE orders SET student_discount = 1 WHERE id = ?')->execute([(int) $order['id']]);
        $order = $this->orders->findById((int) $order['id']);

        $assessment = $this->service->assess($order, PricingService::RESIDENCE_GARENNOIS);

        self::assertTrue($assessment['eligible'], $assessment['problem'] ?? '');
        // At Garennois: (199 * 0.5) + 20 = 119.50. The credit is 42, not the
        // full 84 gap — half of it was already discounted away.
        self::assertSame(42.0, $assessment['amount']);
    }

    public function testRefusesWhenTheRecomputationDoesNotReproduceWhatWasPaid(): void
    {
        // An amount no catalogue produces — stands in for prices edited in
        // /admin/tarifs after the payment, or a hand-made order.
        $order = $this->fulfilledRenewal(311.42);

        $assessment = $this->service->assess($order, PricingService::RESIDENCE_GARENNOIS);

        self::assertFalse($assessment['eligible']);
        self::assertStringContainsString('barème', (string) $assessment['problem']);
    }

    public function testRefusesWithoutAnInvoiceToCredit(): void
    {
        $order = $this->fulfilledRenewal(303.0, withInvoice: false);

        $assessment = $this->service->assess($order, PricingService::RESIDENCE_GARENNOIS);

        self::assertFalse($assessment['eligible']);
        self::assertStringContainsString('facture', (string) $assessment['problem']);
    }

    public function testRefusesOnAnUnpaidOrderBecauseThePriceStillApplies(): void
    {
        $order = $this->fulfilledRenewal(303.0);
        $this->db->pdo()->prepare('UPDATE orders SET status = "pending" WHERE id = ?')->execute([(int) $order['id']]);
        $order = $this->orders->findById((int) $order['id']);

        $assessment = $this->service->assess($order, PricingService::RESIDENCE_GARENNOIS);

        self::assertFalse($assessment['eligible']);
        self::assertStringContainsString('finalisée', (string) $assessment['problem']);
    }

    public function testRefusesOnAnInstallmentOrder(): void
    {
        $order = $this->fulfilledRenewal(303.0);
        $this->db->pdo()->prepare('UPDATE orders SET installment_number = 1 WHERE id = ?')->execute([(int) $order['id']]);
        $order = $this->orders->findById((int) $order['id']);

        $assessment = $this->service->assess($order, PricingService::RESIDENCE_GARENNOIS);

        self::assertFalse($assessment['eligible']);
        self::assertStringContainsString('échelonné', (string) $assessment['problem']);
    }

    public function testRefusesWhenTheOrderWasAlreadyBilledAtTheGrantedGrid(): void
    {
        $order = $this->fulfilledRenewal(303.0);

        $assessment = $this->service->assess($order, PricingService::RESIDENCE_HORS_COMMUNE);

        self::assertFalse($assessment['eligible']);
        self::assertStringContainsString('déjà été facturée', (string) $assessment['problem']);
    }

    public function testRefusesWhenTheGrantedGridIsNotCheaper(): void
    {
        // A Garennois resident granted the hors-commune grid: 199 + 20 = 219 paid,
        // 283 + 20 = 303 at the "granted" grid. Nothing to give back.
        $order = $this->fulfilledRenewal(219.0, [
            'residence'        => PricingService::RESIDENCE_GARENNOIS,
            'pricingResidence' => PricingService::RESIDENCE_GARENNOIS,
        ]);
        $this->db->pdo()
            ->prepare('UPDATE orders SET residence = ?, pricing_residence = ? WHERE id = ?')
            ->execute([PricingService::RESIDENCE_GARENNOIS, PricingService::RESIDENCE_GARENNOIS, (int) $order['id']]);
        $order = $this->orders->findById((int) $order['id']);

        $assessment = $this->service->assess($order, PricingService::RESIDENCE_HORS_COMMUNE);

        self::assertFalse($assessment['eligible']);
        self::assertStringContainsString('rien à rembourser', (string) $assessment['problem']);
    }

    public function testSettledOrderForFindsTheSeasonsOrderThroughMemberFormulas(): void
    {
        $order = $this->fulfilledRenewal(303.0);
        (new \App\Service\RenewalService($this->db, new PricingService(dirname(__DIR__, 2) . '/pricing_data')))
            ->recordFormula(self::SEASON, self::FAKE_BJ_USER_ID, 'heures-pleines', false, false, 0, 0, (int) $order['id'], PricingService::RESIDENCE_HORS_COMMUNE);

        $found = $this->service->settledOrderFor(self::SEASON, self::FAKE_BJ_USER_ID);

        self::assertNotNull($found);
        self::assertSame((int) $order['id'], (int) $found['id']);
        self::assertNull($this->service->settledOrderFor(self::SEASON + 1, self::FAKE_BJ_USER_ID));
    }
}
