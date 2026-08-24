<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One line of a quote/cart. Amounts in euros, 2 decimals.
 */
final readonly class CartLine
{
    public function __construct(
        public string $type,      // 'cotisation' | 'licence' | 'lessons' | 'tickets'
        public string $label,
        public float $amount,
        public float $baseAmount, // amount before prorata discount
        public bool $removable = false,
        public ?int $personIndex = null, // 1 | 2 | null — which registrant a licence line belongs to (couples only)
    ) {
    }
}
