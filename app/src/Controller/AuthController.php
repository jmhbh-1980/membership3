<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Auth\AuthService;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\Mailer;
use App\Support\Csrf;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly BalleJauneClient $bj,
        private readonly Mailer $mailer,
        private readonly PhpRenderer $renderer,
        private readonly Logger $logger,
        private readonly bool $debug,
    ) {
    }

    public function showLogin(Request $request, Response $response): Response
    {
        if (AuthService::currentUser() !== null) {
            return $response->withStatus(302)->withHeader('Location', '/espace');
        }
        return $this->renderer->render($response, 'pages/login.php', [
            'title' => 'Connexion',
            'csrf'  => Csrf::token(),
        ]);
    }

    /**
     * Always answers with the same neutral confirmation page, whether or not
     * the email matches a BJ user — no account enumeration.
     */
    public function submitLogin(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/connexion');
        }

        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        if ($this->debug) {
            $bjUser = filter_var($email, FILTER_VALIDATE_EMAIL) ? $this->auth->findBjUserByEmail($email) : null;
            if ($bjUser === null) {
                $this->logger->info('auth', 'Login attempt for unknown email', ['email' => $email]);
                return $this->renderer->render($response, 'pages/login.php', [
                    'title' => 'Connexion',
                    'csrf'  => Csrf::token(),
                    'error' => 'Email incorrect',
                ]);
            }
            $this->auth->login($bjUser);
            $target = $_SESSION['user']['role'] === AuthService::ROLE_ADMIN ? '/admin' : '/espace';
            return $response->withStatus(302)->withHeader('Location', $target);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $bjUser = $this->auth->findBjUserByEmail($email);
            if ($bjUser !== null) {
                $token = $this->auth->createToken($email, (int) $bjUser['user_id'], $ip);
                if ($token !== null) {
                    $uri = $request->getUri();
                    $link = $uri->getScheme() . '://' . $uri->getAuthority() . '/connexion/verifier?token=' . $token;
                    $this->mailer->send(
                        $email,
                        'Votre lien de connexion — Bad & Squash',
                        '<p>Bonjour ' . htmlspecialchars($bjUser['firstname'] ?? '', ENT_QUOTES) . ',</p>'
                        . '<p>Pour vous connecter à votre espace adhérent, cliquez sur ce lien (valable 15 minutes) :</p>'
                        . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Se connecter</a></p>'
                        . '<p>Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email.</p>',
                        'magic_link',
                    );
                }
            } else {
                $this->logger->info('auth', 'Login attempt for unknown email', ['email' => $email]);
            }
        }

        return $this->renderer->render($response, 'pages/login_sent.php', [
            'title' => 'Vérifiez votre boîte mail',
            'email' => $email,
        ]);
    }

    public function verify(Request $request, Response $response): Response
    {
        $token = (string) ($request->getQueryParams()['token'] ?? '');
        $row = $token !== '' ? $this->auth->consumeToken($token) : null;

        if ($row === null) {
            return $this->renderer->render($response->withStatus(410), 'pages/login_invalid.php', [
                'title' => 'Lien invalide',
            ]);
        }

        $bjUser = $this->bj->get('users/' . (int) $row['bj_user_id'])['user'] ?? null;
        if ($bjUser === null) {
            return $this->renderer->render($response->withStatus(410), 'pages/login_invalid.php', [
                'title' => 'Lien invalide',
            ]);
        }

        $this->auth->login($bjUser);
        $target = $_SESSION['user']['role'] === AuthService::ROLE_ADMIN ? '/admin' : '/espace';
        return $response->withStatus(302)->withHeader('Location', $target);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout();
        return $response->withStatus(302)->withHeader('Location', '/');
    }
}
