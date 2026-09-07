<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\SumUpService;
use App\Support\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Dev-mode only (no API key configured) — no network, matches how the rest
 * of the app tests SumUp-adjacent code without a real sandbox call.
 */
final class SumUpServiceInstallmentTest extends TestCase
{
    private SumUpService $sumup;

    protected function setUp(): void
    {
        $this->sumup = new SumUpService([], new Logger(sys_get_temp_dir() . '/sumup_installment_test.log'));
    }

    public function testCreateCustomerNoOpsInDevMode(): void
    {
        // Just asserts it doesn't throw — dev mode makes no network call.
        $this->sumup->createCustomer('cust-dev-1');
        $this->addToAssertionCount(1);
    }

    public function testCreateTokenizingCheckoutReturnsFakeIdInDevMode(): void
    {
        $result = $this->sumup->createTokenizingCheckout('ref-1', 219.0, 'Test', 'cust-dev-1');
        self::assertSame('DEV-ref-1', $result['checkout_id']);
    }

    public function testChargeTokenSucceedsForOrdinaryAmountInDevMode(): void
    {
        $result = $this->sumup->chargeToken('ref-2', 66.33, 'Test', 'cust-dev-1', 'tok-dev-1');
        self::assertSame('PAID', $result['status']);
    }

    /** Mirrors SumUp's own real sandbox convention for a forced decline — see the testing docs. */
    public function testChargeTokenFailsForSumUpsMagicDeclineAmounts(): void
    {
        foreach ([11.00, 42.01, 42.76, 42.91] as $amount) {
            $result = $this->sumup->chargeToken('ref-3', $amount, 'Test', 'cust-dev-1', 'tok-dev-1');
            self::assertSame('FAILED', $result['status'], "Expected {$amount} to decline");
        }
    }
}
