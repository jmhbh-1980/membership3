<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Builds the `description` sent to SumUp when a checkout is created — the text
 * SumUp prints as the single "Articles" row on the payer's receipt and as the
 * sale's label in the merchant's Ventes list.
 *
 * SumUp's Checkouts API has no line items. POST /checkouts accepts exactly
 * checkout_reference, amount, currency, merchant_code, description, return_url,
 * customer_id, purpose, valid_until, redirect_url and hosted_checkout — there is
 * no basket, no products array — so the receipt can only ever show ONE article,
 * priced at the whole checkout amount. (SumUp's itemised sales come from the
 * Item Catalog in their POS app, which an API checkout cannot reach.) This puts
 * the order's items inside that one row, read from the same persisted cart_lines
 * the invoice prints from, so receipt and invoice name the same things.
 *
 * It deliberately stops at the stored labels plus the prorata. The residence and
 * per-person licence type InvoiceLineComposer appends, and the blurb under each
 * line, are invoice matter — they exist to make an accounting document
 * self-justifying, and would not survive the length available here anyway.
 *
 * Reads nothing but the order's cart_lines, which OrderRepository::create()
 * persists before any checkout exists, so every call site already holds all this
 * needs.
 */
final class CheckoutDescription
{
    private const SEPARATOR = ' + ';

    /**
     * SumUp documents `description` only as "short" and publishes no maximum,
     * so this stays well under any plausible one: losing detail costs the club
     * a little context, while a rejected checkout would break payment outright.
     *
     * Raising it needs SumUp to confirm a real limit first. Until then the
     * abbreviation step below is what buys headroom, and
     * CheckoutDescriptionCatalogueSweepTest is what proves it is enough.
     */
    private const MAX_LENGTH = 200;

    public function __construct(private readonly CheckoutAbbreviations $abbreviations)
    {
    }

    /**
     * @param array  $order    an `orders` row; only cart_lines is read
     * @param string $fallback the flow's own summary, used verbatim when the
     *                         order carries no usable lines or cannot be
     *                         itemised even abbreviated
     */
    public function forOrder(array $order, string $fallback): string
    {
        $lines = json_decode((string) ($order['cart_lines'] ?? '[]'), true);
        if (!is_array($lines)) {
            return $fallback;
        }

        $items = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $label = trim((string) ($line['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $items[] = ['label' => $label, 'prorata' => self::prorataSuffix($line)];
        }
        if ($items === []) {
            return $fallback;
        }

        foreach ($this->renderings($items) as $candidate) {
            if (mb_strlen($candidate) <= self::MAX_LENGTH) {
                return $candidate;
            }
        }

        return $fallback;
    }

    /**
     * Progressively shorter renderings of the same cart, best first. Never a
     * partial list: cutting items off the end would drop the discount row — the
     * one that explains why the amount charged is lower than the items add up
     * to — so detail is shed uniformly instead, and only a cart that cannot fit
     * even abbreviated falls back to its flow's own summary.
     *
     * @param  list<array{label: string, prorata: string}> $items
     * @return iterable<string>
     */
    private function renderings(array $items): iterable
    {
        $render = fn (bool $abbreviate, bool $withProrata): string => implode(
            self::SEPARATOR,
            array_map(
                fn (array $item): string =>
                    ($abbreviate ? $this->abbreviations->apply($item['label']) : $item['label'])
                    . ($withProrata ? $item['prorata'] : ''),
                $items,
            ),
        );

        yield $render(false, true);  // full labels, prorata named
        yield $render(true, true);   // abbreviated, prorata still named
        yield $render(true, false);  // abbreviated, prorata dropped
    }

    /**
     * Names the prorata on the lines that carry one, at the same percentage the
     * invoice prints in its "Réduc." column. Without it the receipt lists a
     * full-season subscription at a part-season price and looks mistaken; the
     * cotisation and lessons lines are the only ones prorated, so licences
     * (always full price) and the promo/student row (its own line) stay bare.
     *
     * @param array<string, mixed> $line one persisted cart_lines entry
     */
    private static function prorataSuffix(array $line): string
    {
        $amount = (float) ($line['amount'] ?? 0.0);
        $baseAmount = isset($line['baseAmount']) ? (float) $line['baseAmount'] : $amount;
        $reduc = InvoiceLineComposer::reducFor($baseAmount, $amount);

        return $reduc === '0' ? '' : ' — prorata -' . $reduc;
    }
}
