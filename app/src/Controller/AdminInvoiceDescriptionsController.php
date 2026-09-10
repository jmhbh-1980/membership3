<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\InvoiceDescriptions;
use App\Service\PricingFileWriter;
use App\Service\PricingService;
use App\Service\Season;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Logger;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/**
 * Editor for pricing_data/invoice_descriptions.php — the short blurb printed
 * under each invoice line. Until now this file could only be changed by editing
 * PHP on disk, which meant nobody ever did: every blurb shipped empty.
 *
 * The fields offered are derived from the current season's catalogue rather
 * than hardcoded, so adding a formula to a barème makes its blurb editable here
 * without a code change. Keys already in the file that the catalogue no longer
 * defines are still shown and still saved — a formula being retired must not
 * silently delete text someone wrote.
 *
 * Unlike the season barèmes there is no draft/publish step: this is boilerplate
 * text, not money, it only affects invoices generated after the change, and a
 * typo here has none of the consequences a wrong price has.
 */
final class AdminInvoiceDescriptionsController
{
    /** Fits the invoice PDF's narrow description column without wrapping into nonsense. */
    private const int MAX_LENGTH = 300;

    /** Entries that are not per-formula, with the label shown next to each field. */
    private const array SINGLETONS = [
        'ticket_pack' => 'Formule Tickets',
        'summer_pack' => 'Pack été',
    ];

    public function __construct(
        private readonly InvoiceDescriptions $descriptions,
        private readonly PricingService $pricing,
        private readonly PricingFileWriter $writer,
        private readonly PhpRenderer $renderer,
        private readonly Db $db,
        private readonly Logger $logger,
        private readonly string $dataDir,
    ) {
    }

    public function edit(Request $request, Response $response): Response
    {
        return $this->render($response, $this->current(), [], (bool) ($request->getQueryParams()['enregistre'] ?? false));
    }

    public function save(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/reglages/descriptions-factures');
        }

        $posted = (array) ($body['blurb'] ?? []);
        $table = ['subscriptions' => [], 'licences' => []];
        $errors = [];

        foreach ($this->fields() as $group => $keys) {
            foreach ($keys as $key) {
                $value = $this->normalize((string) ($posted[$group][$key] ?? ''));
                if (mb_strlen($value) > self::MAX_LENGTH) {
                    $errors[] = "« {$key} » dépasse " . self::MAX_LENGTH . ' caractères.';
                    $value = mb_substr($value, 0, self::MAX_LENGTH);
                }
                if ($group === 'singletons') {
                    $table[$key] = $value;
                } else {
                    $table[$group][$key] = $value;
                }
            }
        }

        if ($errors !== []) {
            // Hand back what they typed, not what's on disk — retyping a long
            // blurb because one field was too long would be its own punishment.
            return $this->render($response, $table, $errors, false);
        }

        $this->writer->write($this->dataDir . '/invoice_descriptions.php', $table);

        $admin = $request->getAttribute('user');
        $this->audit((string) $admin['email'], 'invoice_descriptions.save', [
            'filled' => count(array_filter($this->flatten($table), static fn (string $v): bool => $v !== '')),
        ]);
        $this->logger->info('admin', 'Invoice descriptions saved', ['actor' => $admin['email'] ?? '']);

        return $response->withStatus(302)->withHeader('Location', '/admin/reglages/descriptions-factures?enregistre=1');
    }

    /**
     * Which blurbs exist to edit: this season's formulas and licence kinds,
     * plus any key already written in the file that the catalogue no longer
     * defines, plus the two non-formula entries.
     *
     * @return array{subscriptions: string[], licences: string[], singletons: string[]}
     */
    private function fields(): array
    {
        $season = Season::fromDate(new DateTimeImmutable());
        $stored = $this->current();

        // Both grids merged: Midi has no hors-commune bucket, and a blurb is
        // about the formula, not about who can buy it.
        $subscriptions = array_keys(
            $this->pricing->subscriptionsFor(PricingService::RESIDENCE_GARENNOIS, $season)
            + $this->pricing->subscriptionsFor(PricingService::RESIDENCE_HORS_COMMUNE, $season)
        );

        return [
            'subscriptions' => $this->union($subscriptions, array_keys($stored['subscriptions'] ?? [])),
            'licences'      => $this->union($this->pricing->licenceKinds($season), array_keys($stored['licences'] ?? [])),
            'singletons'    => array_keys(self::SINGLETONS),
        ];
    }

    /** @return string[] catalogue keys first, then any orphan still stored in the file */
    private function union(array $fromCatalogue, array $fromFile): array
    {
        return [...$fromCatalogue, ...array_values(array_diff($fromFile, $fromCatalogue))];
    }

    private function current(): array
    {
        $path = $this->dataDir . '/invoice_descriptions.php';
        $table = is_file($path) ? require $path : [];
        return is_array($table) ? $table : [];
    }

    /**
     * A blurb prints as a single line in the PDF, so newlines and runs of
     * whitespace are collapsed rather than silently mangled at render time.
     */
    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** @return string[] every blurb value, regardless of nesting */
    private function flatten(array $table): array
    {
        $values = [];
        array_walk_recursive($table, static function ($v) use (&$values): void {
            $values[] = (string) $v;
        });
        return $values;
    }

    private function render(Response $response, array $table, array $errors, bool $saved): Response
    {
        $fields = $this->fields();
        $season = Season::fromDate(new DateTimeImmutable());

        $labels = ['subscriptions' => [], 'licences' => []];
        foreach ($fields['subscriptions'] as $key) {
            try {
                $labels['subscriptions'][$key] = (string) $this->pricing->subscription($key, $season)['label'];
            } catch (\Throwable) {
                $labels['subscriptions'][$key] = $key; // orphan: no catalogue entry left to name it
            }
        }
        foreach ($fields['licences'] as $kind) {
            try {
                $labels['licences'][$kind] = (string) $this->pricing->licenceInfo($kind, $season)['label'];
            } catch (\Throwable) {
                $labels['licences'][$kind] = $kind;
            }
        }

        return $this->renderer->render($response, 'pages/admin_invoice_descriptions.php', [
            'title'      => 'Descriptions sur les factures',
            'csrf'       => Csrf::token(),
            'fields'     => $fields,
            'labels'     => $labels,
            'singletons' => self::SINGLETONS,
            'table'      => $table,
            'maxLength'  => self::MAX_LENGTH,
            'errors'     => $errors,
            'saved'      => $saved,
        ]);
    }

    private function audit(string $actor, string $action, array $details = []): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO audit_log (actor, action, entity, entity_id, details, created_at)
             VALUES (?, ?, "settings", "invoice_descriptions", ?, NOW())'
        );
        $stmt->execute([$actor, $action, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }
}
