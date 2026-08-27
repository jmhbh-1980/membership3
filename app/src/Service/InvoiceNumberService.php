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
        $seasonLabel = $this->seasonLabelFor($issuedAt);
        $pdo = $this->db->pdo();

        $pdo->prepare('INSERT IGNORE INTO invoice_counters (season_label, last_number, updated_at) VALUES (?, 0, NOW())')
            ->execute([$seasonLabel]);

        $pdo->prepare('UPDATE invoice_counters SET last_number = LAST_INSERT_ID(last_number + 1), updated_at = NOW() WHERE season_label = ?')
            ->execute([$seasonLabel]);
        $sequence = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();

        return [
            'number'      => sprintf('SQ-%s-%03d', $seasonLabel, $sequence),
            'seasonLabel' => $seasonLabel,
            'sequence'    => $sequence,
        ];
    }
}
