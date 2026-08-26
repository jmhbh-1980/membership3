<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PromoCodeRepository;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Logger;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/**
 * Admin-issued promo codes: create (percent/fixed discount, scope, optional
 * expiry and use-count cap), activate/deactivate, edit, delete and archive.
 *
 * Lifecycle: a code is freely editable and hard-deletable while it has never
 * been attached to a successful order (findByCode/hasSuccessfulUsage). Once
 * used at least once, it locks — no more edit, no delete — because its
 * definition may have already shaped a real payment and the row must survive
 * for the record. To correct a locked code, archive it (keeps the row
 * forever, just moves it off the working list) and create a fresh one.
 */
final class AdminPromoCodeController
{
    public function __construct(
        private readonly PromoCodeRepository $promoCodes,
        private readonly PhpRenderer $renderer,
        private readonly Db $db,
        private readonly Logger $logger,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->renderEditor($response, [], []);
    }

    public function archivedIndex(Request $request, Response $response): Response
    {
        return $this->renderEditor($response, [], [], archived: true);
    }

    public function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/codes-promo');
        }

        [$fields, $errors] = $this->validate($body);
        if ($errors !== []) {
            return $this->renderEditor($response, $body, $errors);
        }

        $admin = $request->getAttribute('user');
        $created = $this->promoCodes->create(
            $fields['code'],
            $fields['kind'],
            $fields['value'],
            $fields['scope'],
            $fields['maxUses'],
            $fields['expiresAt'],
            $fields['note'],
            (string) $admin['email'],
        );
        $this->audit($admin['email'], 'promo_code.create', (int) $created['id'], ['code' => $fields['code']]);
        $this->logger->info('admin', 'Promo code created', ['code' => $fields['code']]);

        return $response->withStatus(302)->withHeader('Location', '/admin/codes-promo');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $code = $this->promoCodes->findById($id);
        if ($code === null || $this->promoCodes->hasSuccessfulUsage($id)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/codes-promo');
        }

        $old = $code;
        if ($old['expires_at'] !== null) {
            $old['expires_at'] = substr((string) $old['expires_at'], 0, 10);
        }

        return $this->renderEditor($response, $old, [], editingId: $id);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        $existing = $this->promoCodes->findById($id);
        if (!Csrf::validate($body['csrf'] ?? null) || $existing === null || $this->promoCodes->hasSuccessfulUsage($id)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/codes-promo');
        }

        [$fields, $errors] = $this->validate($body, $id);
        if ($errors !== []) {
            return $this->renderEditor($response, $body, $errors, editingId: $id);
        }

        $this->promoCodes->update($id, $fields);
        $admin = $request->getAttribute('user');
        $this->audit($admin['email'], 'promo_code.update', $id, ['before' => $existing, 'after' => $fields]);

        return $response->withStatus(302)->withHeader('Location', '/admin/codes-promo');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        $existing = $this->promoCodes->findById($id);
        if (!Csrf::validate($body['csrf'] ?? null) || $existing === null || $this->promoCodes->hasSuccessfulUsage($id)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/codes-promo');
        }

        $admin = $request->getAttribute('user');
        $this->audit($admin['email'], 'promo_code.delete', $id, ['code' => $existing['code']]);
        $this->promoCodes->delete($id);

        return $response->withStatus(302)->withHeader('Location', '/admin/codes-promo');
    }

    public function archive(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null) || $this->promoCodes->findById($id) === null) {
            return $response->withStatus(302)->withHeader('Location', '/admin/codes-promo');
        }

        $this->promoCodes->archive($id);
        $admin = $request->getAttribute('user');
        $this->audit($admin['email'], 'promo_code.archive', $id);

        return $response->withStatus(302)->withHeader('Location', '/admin/codes-promo');
    }

    public function activate(Request $request, Response $response, array $args): Response
    {
        return $this->setActive($request, $response, (int) $args['id'], true);
    }

    public function deactivate(Request $request, Response $response, array $args): Response
    {
        return $this->setActive($request, $response, (int) $args['id'], false);
    }

    private function setActive(Request $request, Response $response, int $id, bool $active): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null) || $this->promoCodes->findById($id) === null) {
            return $response->withStatus(302)->withHeader('Location', '/admin/codes-promo');
        }

        $this->promoCodes->setActive($id, $active);
        $admin = $request->getAttribute('user');
        $this->audit($admin['email'], $active ? 'promo_code.activate' : 'promo_code.deactivate', $id);

        return $response->withStatus(302)->withHeader('Location', '/admin/codes-promo');
    }

    /**
     * @return array{0: array{code:string,kind:string,value:float,scope:string,maxUses:?int,expiresAt:?string,note:string}, 1: string[]}
     */
    private function validate(array $body, ?int $excludeId = null): array
    {
        $errors = [];

        $code = strtoupper(trim((string) ($body['code'] ?? '')));
        if (!preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
            $errors[] = 'Le code doit comporter entre 3 et 32 caractères (lettres, chiffres, tiret, underscore).';
        } else {
            $existing = $this->promoCodes->findByCode($code);
            if ($existing !== null && (int) $existing['id'] !== $excludeId) {
                $errors[] = "Le code « {$code} » existe déjà.";
            }
        }

        $kind = (string) ($body['kind'] ?? '');
        if (!in_array($kind, ['percent', 'fixed'], true)) {
            $errors[] = 'Merci de choisir un type de réduction.';
        }

        $value = (float) ($body['value'] ?? 0);
        if ($value <= 0) {
            $errors[] = 'La valeur de la réduction doit être positive.';
        } elseif ($kind === 'percent' && $value > 100) {
            $errors[] = 'Un pourcentage ne peut pas dépasser 100.';
        }

        $scope = (string) ($body['scope'] ?? 'both');
        if (!in_array($scope, ['join', 'renewal', 'both'], true)) {
            $errors[] = 'Portée invalide.';
        }

        $maxUses = null;
        $maxUsesRaw = trim((string) ($body['max_uses'] ?? ''));
        if ($maxUsesRaw !== '') {
            $maxUses = (int) $maxUsesRaw;
            if ($maxUses < 1) {
                $errors[] = 'Le nombre d\'utilisations maximum doit être au moins 1 (laisser vide pour illimité).';
            }
        }

        $expiresAt = null;
        $expiresRaw = trim((string) ($body['expires_at'] ?? ''));
        if ($expiresRaw !== '') {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $expiresRaw);
            if ($parsed === false) {
                $errors[] = 'Date d\'expiration invalide.';
            } else {
                $expiresAt = $parsed->setTime(23, 59, 59)->format('Y-m-d H:i:s');
            }
        }

        $note = mb_substr(trim((string) ($body['note'] ?? '')), 0, 255);

        return [
            [
                'code'      => $code,
                'kind'      => $kind,
                'value'     => $value,
                'scope'     => $scope,
                'maxUses'   => $maxUses,
                'expiresAt' => $expiresAt,
                'note'      => $note,
            ],
            $errors,
        ];
    }

    private function renderEditor(
        Response $response,
        array $old,
        array $errors,
        bool $archived = false,
        ?int $editingId = null,
    ): Response {
        $rows = $archived ? $this->promoCodes->allArchived() : $this->promoCodes->all();
        $codes = array_map(
            fn (array $c) => $c + [
                'usage'  => $this->promoCodes->usageCount((int) $c['id']),
                'locked' => $this->promoCodes->hasSuccessfulUsage((int) $c['id']),
            ],
            $rows,
        );

        return $this->renderer->render($response, 'pages/admin_promo_codes.php', [
            'title'      => $archived ? 'Codes promo archivés' : 'Codes promo',
            'csrf'       => Csrf::token(),
            'codes'      => $codes,
            'old'        => $old,
            'errors'     => $errors,
            'archived'   => $archived,
            'editingId'  => $editingId,
        ]);
    }

    private function audit(string $actor, string $action, int $entityId, array $details = []): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO audit_log (actor, action, entity, entity_id, details, created_at)
             VALUES (?, ?, "promo_code", ?, ?, NOW())'
        );
        $stmt->execute([$actor, $action, (string) $entityId, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }
}
