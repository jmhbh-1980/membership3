<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Loads pricing_data/invoice_descriptions.php — the short blurb printed
 * under each invoice line item. Flat (not season-versioned): boilerplate
 * text, not pricing, so there's nothing to fall back to.
 */
final class InvoiceDescriptions
{
    private ?array $table = null;

    public function __construct(private readonly string $configDir)
    {
    }

    public function subscriptionBlurb(string $key): string
    {
        return (string) ($this->table()['subscriptions'][$key] ?? '');
    }

    public function licenceBlurb(string $kind): string
    {
        return (string) ($this->table()['licences'][$kind] ?? '');
    }

    public function ticketPackBlurb(): string
    {
        return (string) ($this->table()['ticket_pack'] ?? '');
    }

    public function summerPackBlurb(): string
    {
        return (string) ($this->table()['summer_pack'] ?? '');
    }

    private function table(): array
    {
        if ($this->table === null) {
            $path = $this->configDir . '/invoice_descriptions.php';
            $this->table = is_file($path) ? require $path : [];
        }
        return $this->table;
    }
}
