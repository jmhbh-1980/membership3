<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PromoCodeRepository;

/**
 * Turns a raw `orders` row into displayable itemized data — the shared
 * source for the payment receipt and the admin order-detail page (and,
 * later, the confirmation email / an invoicing feature), so none of those
 * need to re-derive it from cart_lines/promo_code_id themselves.
 */
final class OrderBreakdownService
{
    public function __construct(private readonly PromoCodeRepository $promoCodes)
    {
    }

    /** @return array{lines: array<int, array{type:string,label:string,amount:float}>, promoCode: ?string} */
    public function forOrder(array $order): array
    {
        $lines = json_decode((string) ($order['cart_lines'] ?? '[]'), true) ?: [];

        $promoCode = null;
        if (!empty($order['promo_code_id'])) {
            $promo = $this->promoCodes->findById((int) $order['promo_code_id']);
            $promoCode = $promo['code'] ?? null;
        }

        return ['lines' => $lines, 'promoCode' => $promoCode];
    }
}
