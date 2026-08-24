<?php

declare(strict_types=1);

namespace App\Service\BalleJaune;

/**
 * Resolves BJ subscription names → subscription_id at runtime (IDs are never
 * hardcoded). Names must match the BJ catalogue exactly, whitespace included.
 */
final class SubscriptionResolver
{
    /** @var ?array<string, int> name → id */
    private ?array $byName = null;

    public function __construct(private readonly BalleJauneClient $client)
    {
    }

    public function idForName(string $name): int
    {
        $map = $this->map();
        if (!isset($map[$name])) {
            throw new BalleJauneException("Abonnement introuvable dans Balle Jaune : « {$name} »");
        }
        return $map[$name];
    }

    /** @return array<string, int> */
    public function map(): array
    {
        if ($this->byName === null) {
            $data = $this->client->get('subscriptions');
            $this->byName = [];
            foreach ($data['subscriptions'] ?? [] as $sub) {
                $this->byName[(string) $sub['name']] = (int) $sub['subscription_id'];
            }
        }
        return $this->byName;
    }
}
