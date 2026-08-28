<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\AuditLogRepository;
use App\Repository\OrderRepository;
use App\Service\FulfillmentService;
use App\Service\PaymentSettlementService;
use App\Service\SumUpService;
use App\Support\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests with mocked collaborators (OrderRepository/SumUpService/
 * FulfillmentService/AuditLogRepository aren't final, so createMock() works
 * without touching the real DB or SumUp/BJ — Logger is final, so a real
 * instance pointed at a throwaway file is used instead, matching
 * SettingsRepositoryTest's pattern.
 */
final class PaymentSettlementServiceTest extends TestCase
{
    private function service(OrderRepository $orders, SumUpService $sumup, FulfillmentService $fulfillment, ?AuditLogRepository $auditLog = null): PaymentSettlementService
    {
        return new PaymentSettlementService(
            $orders,
            $sumup,
            $fulfillment,
            new Logger(sys_get_temp_dir() . '/payment_settlement_test.log'),
            $auditLog ?? $this->createMock(AuditLogRepository::class),
        );
    }

    public function testResumeIfOpenReturnsNullWhenNothingOpen(): void
    {
        $sumup = $this->createMock(SumUpService::class);
        $sumup->expects(self::never())->method('checkoutStatus');
        $service = $this->service($this->createMock(OrderRepository::class), $sumup, $this->createMock(FulfillmentService::class));

        self::assertNull($service->resumeIfOpen(null));
    }

    public function testResumeIfOpenReturnsSumUpUrlWhenStillGenuinelyOpen(): void
    {
        $existing = ['id' => 42, 'checkout_reference' => 'ref-42'];
        $sumup = $this->createMock(SumUpService::class);
        $sumup->method('checkoutStatus')->with($existing)->willReturn(['status' => 'PENDING', 'transactionCode' => null, 'url' => 'https://pay.sumup.com/abc']);
        $orders = $this->createMock(OrderRepository::class);
        $orders->expects(self::never())->method('transition');
        $service = $this->service($orders, $sumup, $this->createMock(FulfillmentService::class));

        self::assertSame('https://pay.sumup.com/abc', $service->resumeIfOpen($existing));
    }

    public function testResumeIfOpenFallsBackToOurReturnPageWhenSumUpGivesNoUrl(): void
    {
        $existing = ['id' => 42, 'checkout_reference' => 'ref-42'];
        $sumup = $this->createMock(SumUpService::class);
        $sumup->method('checkoutStatus')->willReturn(['status' => 'PENDING', 'transactionCode' => null, 'url' => null]);
        $service = $this->service($this->createMock(OrderRepository::class), $sumup, $this->createMock(FulfillmentService::class));

        self::assertSame('/paiement/retour/ref-42', $service->resumeIfOpen($existing));
    }

    public function testResumeIfOpenClosesOutAFailedCheckoutAndAllowsAFreshOrder(): void
    {
        $existing = ['id' => 42, 'checkout_reference' => 'ref-42'];
        $sumup = $this->createMock(SumUpService::class);
        $sumup->method('checkoutStatus')->willReturn(['status' => 'FAILED', 'transactionCode' => null, 'url' => null]);
        $orders = $this->createMock(OrderRepository::class);
        $orders->expects(self::once())->method('transition')->with(42, 'pending', 'failed');
        $service = $this->service($orders, $sumup, $this->createMock(FulfillmentService::class));

        self::assertNull($service->resumeIfOpen($existing));
    }

    public function testResumeIfOpenClosesOutAnUncheckableCheckoutRatherThanTrappingTheMember(): void
    {
        // A stale/expired checkout that SumUp itself now errors on looking up —
        // must not block a fresh attempt.
        $existing = ['id' => 42, 'checkout_reference' => 'ref-42'];
        $sumup = $this->createMock(SumUpService::class);
        $sumup->method('checkoutStatus')->willThrowException(new RuntimeException('Erreur SumUp (404).'));
        $orders = $this->createMock(OrderRepository::class);
        $orders->expects(self::once())->method('transition')->with(42, 'pending', 'failed');
        $service = $this->service($orders, $sumup, $this->createMock(FulfillmentService::class));

        self::assertNull($service->resumeIfOpen($existing));
    }

    public function testResumeIfOpenSettlesAndRedirectsToConfirmationWhenAlreadyActuallyPaid(): void
    {
        // The exact incident this was built for: the hosted checkout page errored
        // back to the payer, but the underlying charge had actually succeeded.
        $existing = ['id' => 42, 'status' => 'pending', 'checkout_reference' => 'ref-42', 'meta' => '{}', 'kind' => 'renewal', 'amount' => 169.0];
        $afterTransition = ['id' => 42, 'status' => 'paid', 'checkout_reference' => 'ref-42', 'meta' => '{}', 'kind' => 'renewal', 'amount' => 169.0];

        $sumup = $this->createMock(SumUpService::class);
        $sumup->method('checkoutStatus')->willReturn(['status' => 'PAID', 'transactionCode' => 'TX1', 'url' => null]);

        $orders = $this->createMock(OrderRepository::class);
        $orders->method('transition')->willReturnMap([
            [42, 'pending', 'paid', true],
            [42, 'paid', 'fulfilling', true],
        ]);
        $orders->method('findByReference')->with('ref-42')->willReturn($afterTransition);
        $orders->expects(self::atLeastOnce())->method('update');

        $fulfillment = $this->createMock(FulfillmentService::class);
        $fulfillment->expects(self::once())->method('fulfill');

        $auditLog = $this->createMock(AuditLogRepository::class);
        $auditLog->expects(self::once())->method('log')->with('system', 'order.fulfilled', 'order', '42', self::anything());

        $service = $this->service($orders, $sumup, $fulfillment, $auditLog);

        self::assertSame('/paiement/retour/ref-42', $service->resumeIfOpen($existing));
    }

    public function testSettleAuditsAFailedFulfillmentAttemptAndLeavesTheOrderRetryable(): void
    {
        $order = ['id' => 7, 'status' => 'pending', 'checkout_reference' => 'ref-7', 'meta' => '{}', 'kind' => 'join', 'amount' => 200.0];
        $afterTransition = $order + ['status' => 'paid'];

        $sumup = $this->createMock(SumUpService::class);
        $sumup->method('checkoutStatus')->willReturn(['status' => 'PAID', 'transactionCode' => 'TX7', 'url' => null]);

        $orders = $this->createMock(OrderRepository::class);
        $orders->method('transition')->willReturnMap([
            [7, 'pending', 'paid', true],
            [7, 'paid', 'fulfilling', true],
            [7, 'fulfilling', 'paid', true],
        ]);
        $orders->method('findByReference')->with('ref-7')->willReturn($afterTransition);

        $fulfillment = $this->createMock(FulfillmentService::class);
        $fulfillment->method('fulfill')->willThrowException(new RuntimeException('BJ injoignable.'));

        $auditLog = $this->createMock(AuditLogRepository::class);
        $auditLog->expects(self::once())->method('log')->with('system', 'order.fulfillment_failed', 'order', '7', self::anything());

        $service = $this->service($orders, $sumup, $fulfillment, $auditLog);
        $service->settle($order);
    }

    public function testSettleRecoversAnOrderPreviouslyMarkedFailedIfSumUpNowShowsPaid(): void
    {
        // The exact incident this guards against: a first card attempt was declined
        // and the order got marked 'failed', but SumUp's hosted checkout let the payer
        // retry with a different card on the *same* checkout, which then succeeded —
        // with only a pending→paid transition, that payment would never be picked back
        // up (Thibaud Zaban, order #4: declined, marked failed, paid on retry two
        // minutes later, order stuck at 'failed' with no fulfillment).
        $order = ['id' => 4, 'status' => 'failed', 'checkout_reference' => 'ref-4', 'meta' => '{}', 'kind' => 'renewal', 'amount' => 458.0];
        $afterTransition = ['id' => 4, 'status' => 'paid', 'checkout_reference' => 'ref-4', 'meta' => '{}', 'kind' => 'renewal', 'amount' => 458.0];

        $sumup = $this->createMock(SumUpService::class);
        $sumup->method('checkoutStatus')->willReturn(['status' => 'PAID', 'transactionCode' => 'TX4', 'url' => null]);

        $orders = $this->createMock(OrderRepository::class);
        $orders->method('transition')->willReturnMap([
            [4, 'pending', 'paid', false], // not where it currently is — this leg fails
            [4, 'failed', 'paid', true],   // recovers from here instead
            [4, 'paid', 'fulfilling', true],
        ]);
        $orders->method('findByReference')->with('ref-4')->willReturn($afterTransition);
        $orders->expects(self::atLeastOnce())->method('update');

        $fulfillment = $this->createMock(FulfillmentService::class);
        $fulfillment->expects(self::once())->method('fulfill');

        $service = $this->service($orders, $sumup, $fulfillment);
        $service->settle($order);
    }
}
