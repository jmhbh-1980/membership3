<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\CheckoutAbbreviations;
use App\Service\CheckoutDescription;
use App\Service\InvoiceLineComposer;
use App\Service\PricingService;
use App\Service\Quote;
use App\Service\Season;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * CheckoutDescription degrades in steps (see its forOrder()), and the whole
 * point is that the last step — giving up on itemising altogether — is
 * unreachable for a real order. That is a property of the club's catalogue
 * labels, not of the class, so it can only be shown by sweeping the catalogue:
 * rename a subscription or add a longer one and this is what notices.
 *
 * Sweeps every season file in pricing_data/, and only combinations the app can
 * actually produce — installments are renewal-only and mutually exclusive with
 * a promo code and the student discount (RenewalController::startCheckout()).
 */
final class CheckoutDescriptionCatalogueSweepTest extends TestCase
{
    private const FALLBACK = '<<fallback>>';

    private CheckoutDescription $description;

    protected function setUp(): void
    {
        $this->description = new CheckoutDescription(
            new CheckoutAbbreviations(dirname(__DIR__, 2) . '/pricing_data'),
        );
    }

    /** Enough months to cover both 1- and 2-digit prorata percentages. */
    private const JOIN_MONTHS = [null, 1, 2, 4, 8, 11];

    /** Realistic club codes, plus the 32-char maximum ^[A-Z0-9_-]{3,32}$ allows. */
    private const PROMO_CODES = [null, 'ETE', 'RENTREE25', 'BIENVENUE-NOUVEAUX-ADHERENTS-26'];

    /** @return array<string, array{int}> */
    public static function seasonProvider(): array
    {
        $cases = [];
        foreach (glob(dirname(__DIR__, 2) . '/pricing_data/pricing.*.php') ?: [] as $file) {
            if (preg_match('/pricing\.(\d{4})-\d{4}\.php$/', $file, $m) === 1) {
                $cases[$m[1]] = [(int) $m[1]];
            }
        }
        self::assertNotSame([], $cases, 'no pricing_data/pricing.<season>.php found');

        return $cases;
    }

    /**
     * The guarantee: no order the app can create is ever too long to itemise.
     * A failure here means the SumUp receipt silently stopped listing items for
     * some carts and fell back to the flow's generic summary.
     */
    #[DataProvider('seasonProvider')]
    public function testEveryLegalOrderIsItemised(int $startYear): void
    {
        $measured = 0;
        foreach ($this->legalOrders($startYear) as $case) {
            [$label, $lines] = $case;
            $description = $this->description->forOrder(
                ['cart_lines' => json_encode($lines, JSON_UNESCAPED_UNICODE)],
                self::FALLBACK,
            );
            self::assertNotSame(self::FALLBACK, $description, "not itemised: {$label}");
            $measured++;
        }
        self::assertGreaterThan(1000, $measured, 'sweep collapsed — the generator stopped producing carts');
    }

    /**
     * The stronger guarantee the abbreviation table buys: every legal order
     * keeps its prorata note, not just the items — including the dense couple
     * carts with a 32-character promo code that used to lose it.
     */
    #[DataProvider('seasonProvider')]
    public function testEveryLegalOrderKeepsItsProrataNote(int $startYear): void
    {
        foreach ($this->legalOrders($startYear) as $case) {
            [$label, $lines] = $case;
            $description = $this->description->forOrder(
                ['cart_lines' => json_encode($lines, JSON_UNESCAPED_UNICODE)],
                self::FALLBACK,
            );

            $prorated = array_filter(
                $lines,
                fn (array $l) => InvoiceLineComposer::reducFor((float) $l['baseAmount'], (float) $l['amount']) !== '0',
            );
            if ($prorated === []) {
                continue;
            }
            self::assertStringContainsString('prorata', $description, "prorata note dropped: {$label}");
        }
    }

    /**
     * @param ?list<?string> $promoCodes
     * @return iterable<array{string, list<array<string, mixed>>}>
     */
    private function legalOrders(int $startYear, ?array $promoCodes = null): iterable
    {
        $pricing = new PricingService(dirname(__DIR__, 2) . '/pricing_data');
        $season = new Season($startYear);
        $catalogue = require dirname(__DIR__, 2) . "/pricing_data/pricing.{$startYear}-" . ($startYear + 1) . '.php';
        $promoCodes ??= self::PROMO_CODES;

        foreach ($catalogue['subscriptions'] as $key => $subscription) {
            foreach (array_keys($subscription['individual']) as $residence) {
                foreach ([true, false] as $premiere) {
                    foreach ($subscription['couple_available'] ? [false, true] : [false] as $isCouple) {
                        foreach ($this->peopleSets($isCouple) as $people) {
                            foreach (range(0, $isCouple ? 2 : 1) as $lessons) {
                                foreach (self::JOIN_MONTHS as $month) {
                                    foreach ([false, true] as $summerPack) {
                                        foreach ($promoCodes as $code) {
                                            foreach ([false, true] as $student) {
                                                if ($student && ($isCouple || $code !== null)) {
                                                    continue; // mutually exclusive upstream
                                                }
                                                $joinDate = $month === null
                                                    ? null
                                                    : $season->start()->modify("+{$month} month")->modify('+9 days');
                                                try {
                                                    $quote = $pricing->quote(
                                                        (string) $key, (string) $residence, $premiere, $season,
                                                        joinDate: $joinDate, isCouple: $isCouple, people: $people,
                                                        lessonsCount: $lessons, summerPack: $summerPack,
                                                        studentDiscount: $student,
                                                        promo: $code === null ? null : ['code' => $code, 'kind' => 'percent', 'value' => 10.0],
                                                    );
                                                } catch (Throwable) {
                                                    continue; // combination the app rejects too
                                                }

                                                $label = sprintf(
                                                    '%s/%s/%s/%s/lessons%d/month%s%s/%s%s',
                                                    $key, $residence, $premiere ? 'prem' : 'renew',
                                                    $isCouple ? 'couple' : 'solo', $lessons,
                                                    $month ?? '-', $summerPack ? '/ete' : '',
                                                    $code ?? 'nopromo', $student ? '/etudiant' : '',
                                                );

                                                yield [$label . '/one-shot', self::linesOf($quote)];

                                                // Installments: renewals only, and never with a promo
                                                // code or the student discount.
                                                if ($premiere || $summerPack || $student || $code !== null) {
                                                    continue;
                                                }
                                                foreach ([2, 3] as $count) {
                                                    foreach (self::installmentLines(self::linesOf($quote), $count) as $number => $lines) {
                                                        yield ["{$label}/versement {$number}/{$count}", $lines];
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /** @return list<list<array{competitor: bool, licenceRemoved?: bool}>> */
    private function peopleSets(bool $isCouple): array
    {
        return $isCouple
            ? [
                [['competitor' => true], ['competitor' => false]],
                [['competitor' => true], ['competitor' => true]],
                [['competitor' => true], ['competitor' => false, 'licenceRemoved' => true]],
            ]
            : [
                [['competitor' => true]],
                [['competitor' => false]],
                [['competitor' => true, 'licenceRemoved' => true]],
            ];
    }

    /** @return list<array<string, mixed>> the cart_lines OrderRepository::create() would persist */
    private static function linesOf(Quote $quote): array
    {
        return array_map(fn ($line) => [
            'type' => $line->type, 'label' => $line->label, 'amount' => $line->amount,
            'baseAmount' => $line->baseAmount, 'personIndex' => $line->personIndex,
        ], $quote->lines);
    }

    /**
     * Mirrors RenewalController::startInstallmentPlan(): licences stay whole on
     * installment 1, everything else is split and stamped "(versement n/N)".
     *
     * @param  list<array<string, mixed>> $lines
     * @return array<int, list<array<string, mixed>>> keyed by installment number
     */
    private static function installmentLines(array $lines, int $count): array
    {
        $licences = array_values(array_filter($lines, fn (array $l) => $l['type'] === 'licence'));
        $split = array_values(array_filter($lines, fn (array $l) => $l['type'] !== 'licence'));

        $stamp = static function (array $line, int $number) use ($count): array {
            $line['label'] .= " (versement {$number}/{$count})";
            $line['amount'] = round((float) $line['amount'] / $count, 2);
            $line['baseAmount'] = $line['amount'];

            return $line;
        };

        $out = [1 => [...$licences, ...array_map(fn (array $l) => $stamp($l, 1), $split)]];
        for ($number = 2; $number <= $count; $number++) {
            $later = array_map(fn (array $l) => $stamp($l, $number), $split);
            if ($later !== []) {
                $out[$number] = $later;
            }
        }

        return $out;
    }
}
