<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A computed cart for a join/renewal: the lines and prorata context.
 */
final readonly class Quote
{
    /** @param CartLine[] $lines */
    public function __construct(
        public array $lines,
        public int $prorataMonths,   // complete months elapsed since 1 Sept (0 = full price)
        public string $subscriptionKey,
        public string $bjSubscription,
        public bool $isCouple = false,
    ) {
    }

    public function total(): float
    {
        return round(array_sum(array_map(fn (CartLine $l) => $l->amount, $this->lines)), 2);
    }
}
