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
            $candidates = filter_var($email, FILTER_VALIDATE_EMAIL) ? $this->auth->findAllBjUsersByEmail($email) : [];
            if ($candidates === []) {
                $this->logger->info('auth', 'Login attempt for unknown email', ['email' => $email]);
                return $this->renderer->render($response, 'pages/login.php', [
                    'title' => 'Connexion',
                    'csrf'  => Csrf::token(),
                    'error' => 'Email incorrect',
                ]);
            }
            if (count($candidates) === 1) {
                $this->auth->login($candidates[0]);
                $target = $_SESSION['user']['role'] === AuthService::ROLE_ADMIN ? '/admin' : '/espace';
                return $response->withStatus(302)->withHeader('Location', $target);
            }
            // Ambiguous even in dev mode: no email round-trip to hang a
            // "prove inbox access" step on, so the picker shows right away.
            return $this->renderProfileChoice($response, $candidates);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $candidates = $this->auth->findAllBjUsersByEmail($email);
            if ($candidates !== []) {
                // A shared family email resolves more than one BJ profile: defer
                // the choice to a picker at verify time (see verify()) rather than
                // guessing — the token carries no single bj_user_id yet (0).
                $bjUserId = count($candidates) === 1 ? (int) $candidates[0]['user_id'] : 0;
                $token = $this->auth->createToken($email, $bjUserId, $ip);
                if ($token !== null) {
                    $uri = $request->getUri();
                    $link = $uri->getScheme() . '://' . $uri->getAuthority() . '/connexion/verifier?token=' . $token;
                    $greeting = $bjUserId !== 0 ? ('Bonjour ' . htmlspecialchars($candidates[0]['firstname'] ?? '', ENT_QUOTES) . ',') : 'Bonjour,';
                    $this->mailer->send(
                        $email,
                        'Votre lien de connexion — Bad & Squash',
                        '<p>' . $greeting . '</p>'
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

        if ((int) $row['bj_user_id'] === 0) {
            // Ambiguous at send time: re-resolve live so the picker (or a
            // direct login, if it's since narrowed to one) reflects current
            // BJ data rather than a stale snapshot from when the link was sent.
            $candidates = $this->auth->findAllBjUsersByEmail((string) $row['email']);
            if ($candidates === []) {
                return $this->renderer->render($response->withStatus(410), 'pages/login_invalid.php', [
                    'title' => 'Lien invalide',
                ]);
            }
            if (count($candidates) > 1) {
                return $this->renderProfileChoice($response, $candidates);
            }
            $bjUser = $candidates[0];
        } else {
            $bjUser = $this->bj->get('users/' . (int) $row['bj_user_id'])['user'] ?? null;
            if ($bjUser === null) {
                return $this->renderer->render($response->withStatus(410), 'pages/login_invalid.php', [
                    'title' => 'Lien invalide',
                ]);
            }
        }

        $this->auth->login($bjUser);
        $target = $_SESSION['user']['role'] === AuthService::ROLE_ADMIN ? '/admin' : '/espace';
        return $response->withStatus(302)->withHeader('Location', $target);
    }

    /**
     * Completes login after the member picks their profile on the shared-email
     * picker screen. Never trusts the submitted bj_user_id beyond what was
     * already resolved for this exact login attempt (session-held candidates).
     */
    public function chooseProfile(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/connexion');
        }

        $candidates = $_SESSION['profile_choice']['candidates'] ?? null;
        $chosenId = (int) ($body['bj_user_id'] ?? 0);
        unset($_SESSION['profile_choice']);

        if ($candidates === null || !in_array($chosenId, array_column($candidates, 'user_id'), true)) {
            return $response->withStatus(302)->withHeader('Location', '/connexion');
        }

        $bjUser = $this->bj->get('users/' . $chosenId)['user'] ?? null;
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

    /** @param array[] $bjUsers at least 2 BJ users sharing one email */
    private function renderProfileChoice(Response $response, array $bjUsers): Response
    {
        $candidates = array_map(static fn (array $u) => [
            'user_id'   => (int) $u['user_id'],
            'firstname' => (string) ($u['firstname'] ?? ''),
            'lastname'  => (string) ($u['lastname'] ?? ''),
            'birthday'  => (string) ($u['birthday'] ?? ''),
        ], $bjUsers);

        $_SESSION['profile_choice'] = ['candidates' => $candidates];

        return $this->renderer->render($response, 'pages/login_choose_profile.php', [
            'title'      => 'Qui êtes-vous ?',
            'csrf'       => Csrf::token(),
            'candidates' => $candidates,
        ]);
    }
}
