<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BalleJaune\SubscriptionResolver;
use App\Service\PricingCsvCodec;
use App\Service\PricingFileWriter;
use App\Service\Season;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Logger;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/**
 * Admin pricing editor: create a season (cloned from the most recent one),
 * edit its subscriptions/licences/lessons/ticket-pack in a grid, import/export
 * as CSV, and publish. Edits land in a `.draft.php` file, invisible to
 * PricingService/hasCatalogue() until explicitly published — a half-edited
 * season never affects live renewal availability.
 */
final class AdminPricingController
{
    private const array LICENCE_KEYS = ['pass', 'federale', 'jeune', 'ete'];

    public function __construct(
        private readonly SubscriptionResolver $subscriptions,
        private readonly PricingCsvCodec $csv,
        private readonly PricingFileWriter $writer,
        private readonly PhpRenderer $renderer,
        private readonly Db $db,
        private readonly Logger $logger,
        private readonly string $dataDir,
    ) {
    }

    // ── List & create ────────────────────────────────────────────────────

    public function index(Request $request, Response $response): Response
    {
        return $this->renderer->render($response, 'pages/admin_pricing_list.php', [
            'title'   => 'Barèmes tarifaires',
            'csrf'    => Csrf::token(),
            'seasons' => $this->seasonFiles(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/tarifs');
        }

        $seasons = $this->seasonFiles();
        if ($seasons === []) {
            return $response->withStatus(302)->withHeader('Location', '/admin/tarifs');
        }
        $latestLabel = array_key_last($seasons);
        $source = $seasons[$latestLabel];
        $sourcePath = $source['draft'] ? $this->draftPath($latestLabel) : $this->livePath($latestLabel);
        $catalogue = require $sourcePath;

        $nextLabel = (new Season((int) explode('-', $latestLabel)[0]))->next()->label();
        if (!isset($seasons[$nextLabel])) {
            $catalogue['season_label'] = $nextLabel;
            $this->writer->write($this->draftPath($nextLabel), $catalogue);
            $admin = $request->getAttribute('user');
            $this->audit($admin['email'], 'pricing.create', $nextLabel, ['cloned_from' => $latestLabel]);
        }

        return $response->withStatus(302)->withHeader('Location', '/admin/tarifs/' . $nextLabel);
    }

    // ── Editor ───────────────────────────────────────────────────────────

    public function edit(Request $request, Response $response, array $args): Response
    {
        $label = $args['season'];
        $catalogue = $this->editableCatalogue($label);
        if ($catalogue === null) {
            return $response->withStatus(404);
        }

        return $this->renderEditor($response, $label, $catalogue, []);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $label = $args['season'];
        $reference = $this->editableCatalogue($label);
        $body = (array) $request->getParsedBody();
        if ($reference === null || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/tarifs');
        }

        $submitted = $this->catalogueFromForm($body);
        $errors = $this->validate($submitted, $reference);
        if ($errors !== []) {
            return $this->renderEditor($response, $label, $submitted + ['season_label' => $label], $errors);
        }

        $catalogue = ['season_label' => $label, ...$submitted];
        $this->writer->write($this->draftPath($label), $catalogue);
        $admin = $request->getAttribute('user');
        $this->audit($admin['email'], 'pricing.save', $label);

        return $response->withStatus(302)->withHeader('Location', '/admin/tarifs/' . $label);
    }

    public function publish(Request $request, Response $response, array $args): Response
    {
        $label = $args['season'];
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/tarifs');
        }

        $draftPath = $this->draftPath($label);
        if (!is_file($draftPath)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/tarifs/' . $label);
        }

        $catalogue = require $draftPath;
        $reference = is_file($this->livePath($label)) ? require $this->livePath($label) : $catalogue;
        $errors = $this->validate($catalogue, $reference);
        if ($errors !== []) {
            return $this->renderEditor($response, $label, $catalogue, $errors);
        }

        if (!rename($draftPath, $this->livePath($label))) {
            return $this->renderEditor($response, $label, $catalogue, ['Impossible de publier : erreur d\'écriture.']);
        }

        $admin = $request->getAttribute('user');
        $this->audit($admin['email'], 'pricing.publish', $label);
        $this->logger->info('admin', 'Pricing season published', ['season' => $label]);

        return $response->withStatus(302)->withHeader('Location', '/admin/tarifs/' . $label);
    }

    /**
     * Pulls a published season back to draft. Restricted to seasons that
     * haven't started yet — unpublishing the currently-active season would
     * make PricingService::hasCatalogue() (and therefore renewals) stop
     * seeing it mid-season. If a draft already exists (pending edits since
     * publish), those are left untouched and only the live file is removed;
     * otherwise the live content becomes the new draft so nothing is lost.
     */
    public function unpublish(Request $request, Response $response, array $args): Response
    {
        $label = $args['season'];
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null) || !$this->isFutureSeason($label) || !is_file($this->livePath($label))) {
            return $response->withStatus(302)->withHeader('Location', '/admin/tarifs/' . $label);
        }

        $ok = is_file($this->draftPath($label))
            ? unlink($this->livePath($label))
            : rename($this->livePath($label), $this->draftPath($label));
        if (!$ok) {
            return $this->renderEditor($response, $label, $this->editableCatalogue($label) ?? [], ['Impossible de dépublier : erreur d\'écriture.']);
        }

        $admin = $request->getAttribute('user');
        $this->audit($admin['email'], 'pricing.unpublish', $label);
        $this->logger->info('admin', 'Pricing season unpublished', ['season' => $label]);

        return $response->withStatus(302)->withHeader('Location', '/admin/tarifs/' . $label);
    }

    /** True when a season hasn't started yet — the only ones publish state can safely be reverted for. */
    private function isFutureSeason(string $label): bool
    {
        $startYear = (int) explode('-', $label)[0];
        return $startYear > Season::fromDate(new \DateTimeImmutable())->startYear;
    }

    // ── CSV ──────────────────────────────────────────────────────────────

    public function exportCsv(Request $request, Response $response, array $args): Response
    {
        $label = $args['season'];
        $catalogue = $this->editableCatalogue($label);
        if ($catalogue === null) {
            return $response->withStatus(404);
        }

        $response->getBody()->write($this->csv->toCsv($catalogue));
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="tarifs-' . $label . '.csv"');
    }

    public function importCsv(Request $request, Response $response, array $args): Response
    {
        $label = $args['season'];
        $reference = $this->editableCatalogue($label);
        $body = (array) $request->getParsedBody();
        $files = $request->getUploadedFiles();
        if ($reference === null || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/tarifs');
        }

        $file = $files['csv'] ?? null;
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->renderEditor($response, $label, $reference, ['Merci de sélectionner un fichier CSV.']);
        }

        try {
            $parsed = $this->csv->fromCsv(
                (string) $file->getStream(),
                array_keys($reference['subscriptions']),
            );
        } catch (InvalidArgumentException $e) {
            return $this->renderEditor($response, $label, $reference, [$e->getMessage()]);
        }

        $errors = $this->validate($parsed, $reference);
        // Not saved yet — the admin reviews the imported values in the editor
        // and clicks "Enregistrer" explicitly, same as any manual edit.
        return $this->renderEditor($response, $label, $parsed + ['season_label' => $label], $errors);
    }

    // ── Catalogue loading & form parsing ────────────────────────────────

    /** @return array<string, array{live: bool, draft: bool}> keyed by season label, sorted ascending */
    private function seasonFiles(): array
    {
        $seasons = [];
        foreach (glob($this->dataDir . '/pricing.*.php') ?: [] as $path) {
            if (preg_match('/pricing\.(\d{4}-\d{4})\.php$/', $path, $m)) {
                $seasons[$m[1]]['live'] = true;
                $seasons[$m[1]]['draft'] ??= false;
            }
        }
        foreach (glob($this->dataDir . '/pricing.*.draft.php') ?: [] as $path) {
            if (preg_match('/pricing\.(\d{4}-\d{4})\.draft\.php$/', $path, $m)) {
                $seasons[$m[1]]['draft'] = true;
                $seasons[$m[1]]['live'] ??= false;
            }
        }
        ksort($seasons);
        return $seasons;
    }

    /** Draft if one exists, else live, else null when the season doesn't exist at all. */
    private function editableCatalogue(string $label): ?array
    {
        if (is_file($this->draftPath($label))) {
            return require $this->draftPath($label);
        }
        if (is_file($this->livePath($label))) {
            return require $this->livePath($label);
        }
        return null;
    }

    private function draftPath(string $label): string
    {
        return $this->dataDir . '/pricing.' . $label . '.draft.php';
    }

    private function livePath(string $label): string
    {
        return $this->dataDir . '/pricing.' . $label . '.php';
    }

    /** @return array{subscriptions: array, licences: array, summer_pack: array, lessons: array, ticket_pack: array} */
    private function catalogueFromForm(array $body): array
    {
        $subscriptions = [];
        foreach ((array) ($body['subscriptions'] ?? []) as $key => $s) {
            $coupleAvailable = ($s['couple_available'] ?? '') === '1';
            $entry = [
                'label'            => trim((string) ($s['label'] ?? '')),
                'audience'         => (string) ($s['audience'] ?? ''),
                'couple_available' => $coupleAvailable,
                'bj_subscription'  => trim((string) ($s['bj_subscription'] ?? '')),
                'individual'       => $this->pricesFromForm((array) ($s['individual'] ?? [])),
            ];
            if ($coupleAvailable) {
                $entry['couple'] = $this->pricesFromForm((array) ($s['couple'] ?? []));
            }
            $subscriptions[$key] = $entry;
        }

        $licences = [];
        foreach ((array) ($body['licences'] ?? []) as $key => $l) {
            $licences[$key] = ['label' => trim((string) ($l['label'] ?? '')), 'price' => (float) ($l['price'] ?? 0)];
        }

        $summerPack = [
            'label'      => trim((string) ($body['summer_pack']['label'] ?? '')),
            'cotisation' => (float) ($body['summer_pack']['cotisation'] ?? 0),
        ];
        $lessons = ['label' => trim((string) ($body['lessons']['label'] ?? '')), 'price' => (float) ($body['lessons']['price'] ?? 0)];
        $ticketPack = [
            'label'           => trim((string) ($body['ticket_pack']['label'] ?? '')),
            'tickets'         => (int) ($body['ticket_pack']['tickets'] ?? 0),
            'price'           => (float) ($body['ticket_pack']['price'] ?? 0),
            'bj_subscription' => trim((string) ($body['ticket_pack']['bj_subscription'] ?? '')),
        ];

        return ['subscriptions' => $subscriptions, 'licences' => $licences, 'summer_pack' => $summerPack, 'lessons' => $lessons, 'ticket_pack' => $ticketPack];
    }

    private function pricesFromForm(array $p): array
    {
        $prices = [];
        foreach (['garennois', 'hors-commune'] as $residence) {
            $key = $residence === 'garennois' ? 'garennois' : 'hors_commune';
            $premiere = $p[$key]['premiere'] ?? '';
            $renouvellement = $p[$key]['renouvellement'] ?? '';
            if ($premiere !== '' || $renouvellement !== '') {
                $prices[$residence] = ['premiere' => (float) $premiere, 'renouvellement' => (float) $renouvellement];
            }
        }
        return $prices;
    }

    // ── Validation ───────────────────────────────────────────────────────

    /**
     * Structural + BJ-subscription validation shared by save(), publish()
     * and importCsv(). $reference is the season's currently-stored
     * catalogue: the fixed subscription-key set AND the structural flags
     * (audience/couple_available) are locked to it — only labels, prices
     * and bj_subscription are ever admin-editable. Licence kind is no
     * longer catalogue data (derived live from competitor status), so
     * there's nothing to validate for it here.
     *
     * @return string[]
     */
    private function validate(array $catalogue, array $reference): array
    {
        $errors = [];
        $knownKeys = array_keys($reference['subscriptions']);
        $allBjNames = array_keys($this->subscriptions->map());
        // Subscriptions only ever map to the simplified "_" names; the
        // ticket pack legitimately doesn't (e.g. "FORMULE TICKETS-5"), so
        // it's validated against the full BJ catalogue instead, below.
        $bjNames = array_filter($allBjNames, fn ($n) => str_starts_with($n, '_'));

        $missing = array_diff($knownKeys, array_keys($catalogue['subscriptions'] ?? []));
        foreach ($missing as $key) {
            $errors[] = "Abonnement manquant : {$key}.";
        }
        $unknown = array_diff(array_keys($catalogue['subscriptions'] ?? []), $knownKeys);
        foreach ($unknown as $key) {
            $errors[] = "Abonnement inconnu : {$key} (l'ajout d'abonnement n'est pas permis ici).";
        }

        foreach ($catalogue['subscriptions'] ?? [] as $key => $s) {
            if (!isset($reference['subscriptions'][$key])) {
                continue; // already reported above
            }
            $ref = $reference['subscriptions'][$key];
            foreach (['audience', 'couple_available'] as $lockedField) {
                if (($s[$lockedField] ?? null) !== $ref[$lockedField]) {
                    $errors[] = "« {$lockedField} » ne peut pas être modifié pour {$key}.";
                }
            }
            if ($s['label'] === '') {
                $errors[] = "Le libellé de {$key} est requis.";
            }
            if (!in_array($s['bj_subscription'], $bjNames, true)) {
                $errors[] = "Abonnement Balle Jaune invalide pour {$key} : « {$s['bj_subscription']} ».";
            }
            if (($s['individual'] ?? []) === []) {
                $errors[] = "Aucun tarif individuel renseigné pour {$key}.";
            }
            if (!empty($s['couple_available']) && ($s['couple'] ?? []) === []) {
                $errors[] = "Aucun tarif couple renseigné pour {$key} (couple disponible).";
            }
        }

        foreach (self::LICENCE_KEYS as $key) {
            if (!isset($catalogue['licences'][$key])) {
                $errors[] = "Licence manquante : {$key}.";
            }
        }

        if (!isset($catalogue['summer_pack']['cotisation'])) {
            $errors[] = 'Cotisation du Pack été manquante.';
        }

        if (($catalogue['ticket_pack']['bj_subscription'] ?? '') !== '' && !in_array($catalogue['ticket_pack']['bj_subscription'], $allBjNames, true)) {
            $errors[] = 'Abonnement Balle Jaune invalide pour la formule tickets.';
        }

        return $errors;
    }

    // ── Rendering & audit ────────────────────────────────────────────────

    private function renderEditor(Response $response, string $label, array $catalogue, array $errors): Response
    {
        $allBjNames = array_keys($this->subscriptions->map());
        $bjNames = array_values(array_filter($allBjNames, fn ($n) => str_starts_with($n, '_')));
        sort($bjNames);
        sort($allBjNames);

        return $this->renderer->render($response, 'pages/admin_pricing_editor.php', [
            'title'       => 'Barème ' . $label,
            'csrf'        => Csrf::token(),
            'label'       => $label,
            'catalogue'   => $catalogue,
            'isLive'      => is_file($this->livePath($label)) && !is_file($this->draftPath($label)),
            'canUnpublish' => is_file($this->livePath($label)) && $this->isFutureSeason($label),
            'bjNames'     => $bjNames,
            'allBjNames'  => $allBjNames,
            'errors'      => $errors,
        ]);
    }

    private function audit(string $actor, string $action, string $season, array $details = []): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO audit_log (actor, action, entity, entity_id, details, created_at)
             VALUES (?, ?, "pricing_season", ?, ?, NOW())'
        );
        $stmt->execute([$actor, $action, $season, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }
}
