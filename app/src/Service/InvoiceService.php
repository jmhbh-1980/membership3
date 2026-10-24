<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\InvoiceRepository;
use App\Support\Logger;
use DateTimeImmutable;

/**
 * Facade orchestrating invoice generation: idempotent (returns the existing
 * invoice if this order already has one) and failure-isolated
 * (generateForOrder() never throws — a broken invoice must never abort
 * fulfillment or re-trigger BJ writes on retry, see FulfillmentService).
 */
final class InvoiceService
{
    /**
     * No retroactive invoices: this feature only generates for orders
     * created on/after its ship date — the admin manual-recovery button is
     * gated on this too, never offered for older orders.
     */
    public const string ELIGIBLE_SINCE = '2026-08-27';

    /**
     * Every order kind that gets an invoice: a membership (join, renewal), and
     * what a member adds to one afterwards (cours collectifs, ticket credits).
     */
    public const array KINDS = ['join', 'renewal', 'lessons', 'credits'];

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceNumberService $numbers,
        private readonly OrderBreakdownService $breakdown,
        private readonly InvoiceLineComposer $composer,
        private readonly InvoicePdfService $pdf,
        private readonly string $uploadsDir,
        private readonly Logger $logger,
    ) {
    }

    public function isEligible(array $order): bool
    {
        return in_array($order['kind'], self::KINDS, true)
            && (string) $order['created_at'] >= self::ELIGIBLE_SINCE;
    }

    /**
     * The context for an order that adds to a membership instead of being one
     * (cours collectifs, ticket credits): its lines need nothing from a
     * formula, only who is billed and, for lessons, the season they run in.
     * Credits never expire, so no period is printed for them.
     */
    public static function addOnContext(array $order, array $billingUser): array
    {
        $meta = json_decode((string) ($order['meta'] ?? '{}'), true) ?: [];
        $seasonStartYear = (int) ($meta['seasonStartYear'] ?? 0);
        return [
            'season'         => $order['kind'] === 'lessons' && $seasonStartYear > 0 ? new Season($seasonStartYear) : null,
            'billingName'    => trim(($billingUser['firstname'] ?? '') . ' ' . ($billingUser['lastname'] ?? '')),
            'billingAddress' => [
                'address'    => $billingUser['address'] ?? '',
                'postalcode' => $billingUser['postalcode'] ?? '',
                'city'       => $billingUser['city'] ?? '',
            ],
        ];
    }

    /**
     * @param array $context see InvoiceLineComposer::compose()'s $context, plus
     *                        billingName/billingAddress for the PDF header.
     * @param ?DateTimeImmutable $issuedAt normally omitted (defaults to now, the real
     *                        issuance moment) — only ever overridden by a manual
     *                        backfill (see bin/backfill_manual_join.php) recording a
     *                        payment from before this app existed, so the invoice
     *                        lands in the correct Aug1-Jul31 bookkeeping year/sequence
     *                        (see InvoiceNumberService) instead of today's.
     * @param ?string $manualNumber normally omitted (the SQ-<year>-<n> auto-sequence
     *                        applies). Only set by a manual backfill for a bookkeeping
     *                        year the club already numbered by hand before this app
     *                        existed (or ran its own separate sequence) — this app's
     *                        own counter for that year is left untouched (never
     *                        allocated/incremented), so it can't drift out of sync
     *                        with a numbering scheme it was never part of.
     */
    public function generateForOrder(array $order, array $context, ?DateTimeImmutable $issuedAt = null, ?string $manualNumber = null): ?array
    {
        $existing = $this->invoices->findByOrderId((int) $order['id']);
        if ($existing !== null) {
            return $existing;
        }
        if (!$this->isEligible($order)) {
            return null;
        }

        try {
            $issuedAt ??= new DateTimeImmutable();
            $allocation = $manualNumber !== null
                ? ['number' => $manualNumber, 'seasonLabel' => $this->numbers->seasonLabelFor($issuedAt), 'sequence' => 0]
                : $this->numbers->allocate($issuedAt);
            $lines = $this->composer->compose($this->breakdown->forOrder($order), $context);
            $pdfPath = $this->pdf->generate(
                $allocation,
                $issuedAt,
                $order,
                (string) $context['billingName'],
                (array) $context['billingAddress'],
                $lines,
                // Tickets are credits with no end date: there is no season to state.
                ($context['subscriptionKey'] ?? '') === PricingService::TICKETS ? null : ($context['season'] ?? null),
            );
            return $this->invoices->create((int) $order['id'], $allocation, $pdfPath, $issuedAt, (float) $order['amount']);
        } catch (\Throwable $e) {
            $this->logger->error('invoice', 'Génération de facture échouée', [
                'order_id' => $order['id'], 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** @return array{filename:string, content:string, mime:string} Mailer attachment shape */
    public function attachmentFor(array $invoice): array
    {
        return [
            'filename' => 'Facture-' . $invoice['number'] . '.pdf',
            'content'  => (string) file_get_contents($this->uploadsDir . '/' . $invoice['pdf_path']),
            'mime'     => 'application/pdf',
        ];
    }
}
