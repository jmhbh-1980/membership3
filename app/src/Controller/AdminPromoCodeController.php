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
 * expiry and use-count cap) and activate/deactivate. No edit/delete — once a
 * code may have been used, changing its value or removing it would make past
 * orders' discount_amount inexplicable, so a code is only ever superseded by
 * a new one (mirrors AdminPricingController's publish/unpublish pattern).
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
    private function validate(array $body): array
    {
        $errors = [];

        $code = strtoupper(trim((string) ($body['code'] ?? '')));
        if (!preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
            $errors[] = 'Le code doit comporter entre 3 et 32 caractères (lettres, chiffres, tiret, underscore).';
        } elseif ($this->promoCodes->findByCode($code) !== null) {
            $errors[] = "Le code « {$code} » existe déjà.";
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

    private function renderEditor(Response $response, array $old, array $errors): Response
    {
        $codes = array_map(
            fn (array $c) => $c + ['usage' => $this->promoCodes->usageCount((int) $c['id'])],
            $this->promoCodes->all(),
        );

        return $this->renderer->render($response, 'pages/admin_promo_codes.php', [
            'title'  => 'Codes promo',
            'csrf'   => Csrf::token(),
            'codes'  => $codes,
            'old'    => $old,
            'errors' => $errors,
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
