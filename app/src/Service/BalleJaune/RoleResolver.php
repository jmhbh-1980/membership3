<?php

declare(strict_types=1);

namespace App\Service\BalleJaune;

/**
 * Resolves BJ role (ACL) names → acl_id at runtime. Used to create new
 * members as "Visiteur" (restricted) until payment + shoes check, then
 * promote them to "Membre".
 */
final class RoleResolver
{
    /** @var ?array<string, int> lowercased name → id */
    private ?array $byName = null;

    public function __construct(private readonly BalleJauneClient $client)
    {
    }

    public function idForName(string $name): int
    {
        if ($this->byName === null) {
            $this->byName = [];
            foreach ($this->client->get('roles')['roles'] ?? [] as $role) {
                $this->byName[mb_strtolower((string) $role['name'])] = (int) $role['acl_id'];
            }
        }
        $key = mb_strtolower($name);
        if (!isset($this->byName[$key])) {
            throw new BalleJauneException("Rôle introuvable dans Balle Jaune : « {$name} »");
        }
        return $this->byName[$key];
    }
}
