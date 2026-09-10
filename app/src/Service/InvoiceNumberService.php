<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Db;
use DateTimeImmutable;

/**
 * Allocates sequential invoice numbers "SQ-{season}-{n}". Numbering resets
 * on 1 August each year — a full month before the club's actual Season
 * boundary (1 Sept) — per the treasurer's own bookkeeping-year convention.
 * Deliberately independent of Season::label(): different boundary, different
 * purpose. Never reuse or alias Season here.
 */
final class InvoiceNumberService
{
    public function __construct(private readonly Db $db)
    {
    }

    /** Dates from 1 Aug of year Y through 31 Jul of Y+1 → "Y-(Y+1)". */
    public function seasonLabelFor(DateTimeImmutable $date): string
    {
        $year = (int) $date->format('Y');
        $augFirst = $date->setDate($year, 8, 1)->setTime(0, 0, 0);
        $startYear = $date >= $augFirst ? $year : $year - 1;
        return $startYear . '-' . ($startYear + 1);
    }

    /**
     * Atomically allocates the next number for this issue date's invoicing
     * year. The UPDATE takes an InnoDB row lock on that season's single
     * counter row, so concurrent callers serialize there — no gaps, no
     * duplicates, no explicit transaction needed.
     *
     * @return array{number:string, seasonLabel:string, sequence:int}
     */
    public function allocate(DateTimeImmutable $issuedAt): array
    {
        return $this->allocateFrom('invoice_counters', 'SQ', $issuedAt);
    }

    /**
     * Same algorithm and same Aug1-Jul31 bookkeeping year, but its own
     * sequence: credit notes (avoirs) are numbered AV-<year>-<n> from
     * credit_note_counters. Kept a separate counter row set on purpose — an
     * avoir must never consume or shift a facture number.
     *
     * @return array{number:string, seasonLabel:string, sequence:int}
     */
    public function allocateCreditNote(DateTimeImmutable $issuedAt): array
    {
        return $this->allocateFrom('credit_note_counters', 'AV', $issuedAt);
    }

    /** @param string $table trusted literal from this class only — never user input (interpolated into SQL) */
    private function allocateFrom(string $table, string $prefix, DateTimeImmutable $issuedAt): array
    {
        $seasonLabel = $this->seasonLabelFor($issuedAt);
        $pdo = $this->db->pdo();

        $pdo->prepare("INSERT IGNORE INTO {$table} (season_label, last_number, updated_at) VALUES (?, 0, NOW())")
            ->execute([$seasonLabel]);

        $pdo->prepare("UPDATE {$table} SET last_number = LAST_INSERT_ID(last_number + 1), updated_at = NOW() WHERE season_label = ?")
            ->execute([$seasonLabel]);
        $sequence = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();

        return [
            'number'      => sprintf('%s-%s-%03d', $prefix, $seasonLabel, $sequence),
            'seasonLabel' => $seasonLabel,
            'sequence'    => $sequence,
        ];
    }
}
