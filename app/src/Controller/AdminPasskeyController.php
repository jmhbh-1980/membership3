<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WebauthnCredentialRepository;
use App\Service\Auth\AuthService;
use App\Service\Auth\WebauthnService;
use App\Support\Csrf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;
use Throwable;

/**
 * Admin-only passkey enrollment/management — see WebauthnService for the
 * WebAuthn ceremony itself and migration 0025 for why login needs no
 * separate role check on top of this being the only place a credential can
 * ever be created.
 */
final class AdminPasskeyController
{
    public function __construct(
        private readonly WebauthnService $webauthn,
        private readonly WebauthnCredentialRepository $credentials,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $admin = AuthService::currentUser();

        return $this->renderer->render($response, 'pages/admin_passkeys.php', [
            'title'    => "Clés d'accès",
            'csrf'     => Csrf::token(),
            'passkeys' => $this->credentials->forUser((int) $admin['bj_user_id']),
        ]);
    }

    public function registerOptions(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $this->json($response->withStatus(403), ['error' => 'Session invalide, rechargez la page.']);
        }

        $admin = AuthService::currentUser();
        $result = $this->webauthn->registrationOptions(
            $request->getUri()->getHost(),
            (int) $admin['bj_user_id'],
            (string) $admin['email'],
            trim($admin['firstname'] . ' ' . $admin['lastname']),
        );
        $_SESSION['webauthn_register_challenge'] = $result['challenge'];

        return $this->json($response, $result['options']);
    }

    public function register(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $this->json($response->withStatus(403), ['error' => 'Session invalide, rechargez la page.']);
        }

        $challenge = $_SESSION['webauthn_register_challenge'] ?? null;
        unset($_SESSION['webauthn_register_challenge']);
        if ($challenge === null) {
            return $this->json($response->withStatus(400), ['error' => 'Session expirée, réessayez.']);
        }

        $admin = AuthService::currentUser();
        $label = trim((string) ($body['label'] ?? '')) ?: "Clé d'accès";

        try {
            $this->webauthn->verifyRegistration($request->getUri()->getHost(), $body, $challenge, (int) $admin['bj_user_id'], $label);
        } catch (Throwable $e) {
            return $this->json($response->withStatus(400), ['error' => $e->getMessage()]);
        }

        return $this->json($response, ['ok' => true]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        if (Csrf::validate($body['csrf'] ?? null)) {
            $admin = AuthService::currentUser();
            $this->credentials->delete((int) $args['id'], (int) $admin['bj_user_id']);
        }

        return $response->withStatus(302)->withHeader('Location', '/admin/reglages/passkeys');
    }

    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
