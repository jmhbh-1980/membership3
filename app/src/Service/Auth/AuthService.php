<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Repository\AuditLogRepository;
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

    public const string ROLE_ADMIN = 'admin';
    public const string ROLE_MEMBER = 'member';

    public function __construct(
        private readonly Db $db,
        private readonly BalleJauneClient $bj,
        private readonly Logger $logger,
        private readonly AuditLogRepository $auditLog,
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
     * Creates a magic-link token for a BJ user. $bjUserId is 0 when the email
     * matched more than one BJ profile — the choice is deferred to a picker
     * screen at verify time instead (see AuthController::verify()). Returns
     * the clear token to embed in the link, or null when the rate limit is
     * hit.
     */
    public function createToken(string $email, int $bjUserId, string $ip): ?string
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
        $stmt = $pdo->prepare(
            'INSERT INTO magic_tokens (email, token_hash, bj_user_id, purpose, created_ip, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . self::TOKEN_TTL_MINUTES . ' MINUTE), NOW())'
        );
        $stmt->execute([$email, hash('sha256', $token), $bjUserId, 'login', $ip]);

        return $token;
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

    public static function currentUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }
}
