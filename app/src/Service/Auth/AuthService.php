<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Repository\AuditLogRepository;
use App\Repository\SettingsRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Support\Db;
use App\Support\Logger;
use DateTimeImmutable;

/**
 * Passwordless authentication: the login identifier is the email, matched
 * against Balle Jaune users (source of truth). A single-use magic link,
 * valid 15 minutes, is emailed; tokens are stored hashed (SHA-256).
 */
class AuthService
{
    private const int TOKEN_TTL_MINUTES = 15;
    private const int MAX_REQUESTS_PER_WINDOW = 3;
    private const int MAX_CODE_ATTEMPTS = 5;

    public const string ROLE_ADMIN = 'admin';
    public const string ROLE_MEMBER = 'member';

    public function __construct(
        private readonly Db $db,
        private readonly BalleJauneClient $bj,
        private readonly Logger $logger,
        private readonly AuditLogRepository $auditLog,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * Finds every BJ user whose email or email2 matches exactly
     * (case-insensitive) — a shared family email (parent + kids, each their
     * own BJ profile) can return more than one row.
     *
     * @return array[]
     */
    public function findAllBjUsersByEmail(string $email): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return [];
        }
        $data = $this->bj->get('users', ['search' => $email, 'limit' => 50]);
        return array_values(array_filter(
            $data['users'] ?? [],
            fn (array $user) => mb_strtolower($user['email'] ?? '') === $email || mb_strtolower($user['email2'] ?? '') === $email,
        ));
    }

    /**
     * Creates a magic-link token (and a paired short code — an alternative
     * for whoever can't tap the link: an iOS home-screen shortcut opens the
     * link in Safari instead of the shortcut itself, and a corporate mail
     * gateway that prefetches links can't "type" a code into a form) for a
     * BJ user. $bjUserId is 0 when the email matched more than one BJ
     * profile — the choice is deferred to a picker screen at verify time
     * instead (see AuthController::verify()). Returns null when the rate
     * limit is hit.
     *
     * @return null|array{token: string, code: string}
     */
    public function createToken(string $email, int $bjUserId, string $ip): ?array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM magic_tokens
             WHERE (email = ? OR created_ip = ?) AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $stmt->execute([$email, $ip]);
        if ((int) $stmt->fetchColumn() >= self::MAX_REQUESTS_PER_WINDOW) {
            $this->logger->info('auth', 'Rate limit magic link', ['email' => $email, 'ip' => $ip]);
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare(
            'INSERT INTO magic_tokens (email, token_hash, code_hash, bj_user_id, purpose, created_ip, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . self::TOKEN_TTL_MINUTES . ' MINUTE), NOW())'
        );
        $stmt->execute([$email, hash('sha256', $token), hash('sha256', $code), $bjUserId, 'login', $ip]);

        return ['token' => $token, 'code' => $code];
    }

    /**
     * Consumes a magic-link token. Returns the token row when valid
     * (unused, unexpired), null otherwise. Single use: marked used atomically.
     */
    public function consumeToken(string $token): ?array
    {
        $pdo = $this->db->pdo();
        $hash = hash('sha256', $token);

        $stmt = $pdo->prepare(
            'UPDATE magic_tokens SET used_at = NOW()
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute([$hash]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT * FROM magic_tokens WHERE token_hash = ?');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Consumes a magic-code (the alternative to consumeToken() sharing the
     * same row — using either invalidates both). A code has only 1-in-a-
     * million odds, so guessing is capped at MAX_CODE_ATTEMPTS per email
     * across every currently pending token for it; once locked, further
     * attempts return null without touching used_at, so a wrong guess can
     * never burn the still-valid link for that same row.
     */
    public function consumeCode(string $email, string $code): ?array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT * FROM magic_tokens WHERE email = ? AND used_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute([$email]);
        $pending = $stmt->fetchAll();

        foreach ($pending as $row) {
            if ((int) $row['code_attempts'] >= self::MAX_CODE_ATTEMPTS) {
                return null;
            }
        }

        $hash = hash('sha256', $code);
        foreach ($pending as $row) {
            if ($row['code_hash'] !== null && hash_equals($row['code_hash'], $hash)) {
                $stmt = $pdo->prepare('UPDATE magic_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
                $stmt->execute([$row['id']]);
                return $stmt->rowCount() === 1 ? $row : null;
            }
        }

        $stmt = $pdo->prepare(
            'UPDATE magic_tokens SET code_attempts = code_attempts + 1
             WHERE email = ? AND used_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute([$email]);
        return null;
    }

    /**
     * Resolves the app role of a BJ user from its acl_id (Administrateur →
     * admin, everything else → member).
     */
    public function roleForUser(array $bjUser): string
    {
        static $rolesById = null;
        if ($rolesById === null) {
            $rolesById = [];
            foreach ($this->bj->get('roles')['roles'] ?? [] as $role) {
                $rolesById[(int) $role['acl_id']] = (string) $role['name'];
            }
        }
        $name = $rolesById[(int) ($bjUser['acl_id'] ?? 0)] ?? '';
        return strcasecmp($name, 'Administrateur') === 0 ? self::ROLE_ADMIN : self::ROLE_MEMBER;
    }

    /** Opens the session for a BJ user. */
    public function login(array $bjUser): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'bj_user_id' => (int) $bjUser['user_id'],
            'email'      => (string) ($bjUser['email'] ?? ''),
            'firstname'  => (string) ($bjUser['firstname'] ?? ''),
            'lastname'   => (string) ($bjUser['lastname'] ?? ''),
            'role'       => $this->roleForUser($bjUser),
            'login_at'   => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
        $this->logger->info('auth', 'Login', ['bj_user_id' => $_SESSION['user']['bj_user_id'], 'role' => $_SESSION['user']['role']]);
        // Member logins aren't audited — too high-volume to be a meaningful signal
        // there — but who accessed /admin and when is exactly what this trail is for.
        if ($_SESSION['user']['role'] === self::ROLE_ADMIN) {
            $this->auditLog->log($_SESSION['user']['email'], 'auth.admin_login', 'bj_user', (string) $_SESSION['user']['bj_user_id']);
        }
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Force-logout hook for maintenance deploys (see bin/maintenance.php):
     * a session opened before the `sessions_invalidated_at` setting is
     * treated as stale and cleared, so RequireRole bounces the visitor back
     * to the login page instead of trusting a pre-deploy cookie. Checked on
     * every guarded request (RequireRole), not just at login time, since the
     * whole point is to catch sessions that were already open.
     */
    public function clearIfInvalidated(): void
    {
        $user = $_SESSION['user'] ?? null;
        if ($user === null) {
            return;
        }

        $invalidatedAt = $this->settings->get('sessions_invalidated_at');
        if ($invalidatedAt !== null && ($user['login_at'] ?? '') < $invalidatedAt) {
            $this->logout();
        }
    }

    public static function currentUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }
}
