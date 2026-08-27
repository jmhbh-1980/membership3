<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns an order's persisted cart_lines (money, never recomputed — see
 * InvoiceService) into the rows an invoice prints. For the cotisation line
 * on a yearly subscription, this is where all four required dimensions end
 * up in the text: (a) garennois/hors-commune, (b) tier — already in the
 * stored label, (c) 1ère inscription/renouvellement — already in the stored
 * label, (d) licence type per person.
 *
 * The stored label is always reused verbatim as the base text (it's what was
 * actually charged); this only appends to it, never re-derives it.
 */
final class InvoiceLineComposer
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly InvoiceDescriptions $descriptions,
    ) {
    }

    /**
     * @param array $breakdown OrderBreakdownService::forOrder() output
     * @param array $context {
     *     subscription: array (catalogue entry), subscriptionKey: string, season: Season,
     *     residence: string, summerPack: bool,
     *     people: list<array{competitor:bool, licenceRemoved:bool}> (0-indexed, 1 or 2 entries)
     * }
     * @return list<array{description:string, blurb:string, quantity:int, unitPrice:float, reduc:string, amount:float}>
     */
    public function compose(array $breakdown, array $context): array
    {
        $rows = [];
        foreach ($breakdown['lines'] as $line) {
            $type = (string) $line['type'];
            $label = (string) $line['label'];
            $amount = (float) $line['amount'];
            $baseAmount = isset($line['baseAmount']) ? (float) $line['baseAmount'] : $amount;
            $personIndex = isset($line['personIndex']) ? (int) $line['personIndex'] : null;

            [$description, $blurb] = match ($type) {
                'cotisation' => [$this->cotisationDescription($label, $context), $this->cotisationBlurb($context)],
                'licence'    => [$label, $this->descriptions->licenceBlurb($this->licenceKindForPerson($context, $personIndex))],
                default      => [$label, ''],
            };

            $quantity = 1;
            if ($type === 'lessons' && preg_match('/×\s*(\d+)/u', $label, $m) === 1) {
                $quantity = (int) $m[1];
            }
            $unitPrice = $quantity > 0 ? round($baseAmount / $quantity, 2) : $baseAmount;

            $rows[] = [
                'description' => $description,
                'blurb'       => $blurb,
                'quantity'    => $quantity,
                'unitPrice'   => $unitPrice,
                'reduc'       => $this->reducFor($baseAmount, $amount),
                'amount'      => $amount,
            ];
        }
        return $rows;
    }

    /** The prorata (or a mid-season change) is the only source of a per-row reduction in this app — a promo code is its own separate row, never folded in here. */
    private function reducFor(float $baseAmount, float $amount): string
    {
        if ($baseAmount === 0.0 || $baseAmount === $amount) {
            return '0';
        }
        return (string) (int) round((1 - $amount / $baseAmount) * 100) . '%';
    }

    private function cotisationDescription(string $label, array $context): string
    {
        if (!empty($context['summerPack'])) {
            return $label . $this->licenceSuffix($context);
        }
        $residenceLabel = $context['residence'] === PricingService::RESIDENCE_GARENNOIS ? 'Garennois(e)' : 'Hors commune';
        return $label . ' — ' . $residenceLabel . $this->licenceSuffix($context);
    }

    private function cotisationBlurb(array $context): string
    {
        return !empty($context['summerPack'])
            ? $this->descriptions->summerPackBlurb()
            : $this->descriptions->subscriptionBlurb((string) $context['subscriptionKey']);
    }

    /** Appends dimension (d): licence type, per person, spelled out — never parsed back out of a label string. */
    private function licenceSuffix(array $context): string
    {
        $people = $context['people'] ?? [];
        if (count($people) <= 1) {
            if (!empty($people[0]['licenceRemoved'] ?? false)) {
                return ' — sans licence (retirée)';
            }
            return ' — licence ' . $this->licenceLabel($this->licenceKindForPerson($context, null, 0), $context);
        }

        $removed0 = !empty($people[0]['licenceRemoved'] ?? false);
        $removed1 = !empty($people[1]['licenceRemoved'] ?? false);
        if ($removed0 && $removed1) {
            return ' — sans licence (retirée)';
        }
        $kind0 = $this->licenceKindForPerson($context, null, 0);
        $kind1 = $this->licenceKindForPerson($context, null, 1);
        if (!$removed0 && !$removed1 && $kind0 === $kind1) {
            return ' — licence ' . $this->licenceLabel($kind0, $context) . ' (2 personnes)';
        }
        $part0 = $removed0 ? 'sans licence (vous)' : $this->licenceLabel($kind0, $context) . ' (vous)';
        $part1 = $removed1 ? 'sans licence (conjoint(e))' : $this->licenceLabel($kind1, $context) . ' (conjoint(e))';
        return ' — licences : ' . $part0 . ', ' . $part1;
    }

    /** @param ?int $personIndex CartLine's personIndex (1|2|null — null on a solo registration) */
    private function licenceKindForPerson(array $context, ?int $personIndex, ?int $fallbackIndex = null): string
    {
        if (!empty($context['summerPack'])) {
            return 'ete';
        }
        $index = $personIndex !== null ? $personIndex - 1 : ($fallbackIndex ?? 0);
        $person = $context['people'][$index] ?? ['competitor' => false];
        return $this->pricing->licenceKindFor((string) $context['subscription']['audience'], (bool) ($person['competitor'] ?? false));
    }

    private function licenceLabel(string $kind, array $context): string
    {
        return $this->pricing->licenceInfo($kind, $context['season'])['label'];
    }
}
