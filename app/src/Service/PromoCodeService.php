<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PromoCodeRepository;
use DateTimeImmutable;

/**
 * Resolves a member/applicant-entered code into a discount, or an error to
 * show them. Kept separate from PricingService (which stays pure/DB-free) —
 * callers pass PricingService::quote() only the already-resolved discount.
 */
final class PromoCodeService
{
    public function __construct(private readonly PromoCodeRepository $repository)
    {
    }

    /**
     * @param string $scope 'join' | 'renewal' — the checkout this code is being applied to
     * @return array{ok: bool, promo: ?array{code: string, kind: string, value: float}, error: ?string}
     */
    public function resolve(?string $code, string $scope): array
    {
        $normalized = strtoupper(trim((string) $code));
        if ($normalized === '') {
            return ['ok' => true, 'promo' => null, 'error' => null];
        }

        $row = $this->repository->findByCode($normalized);
        if ($row === null) {
            return ['ok' => false, 'promo' => null, 'error' => 'invalid'];
        }
        if (!(bool) $row['active']) {
            return ['ok' => false, 'promo' => null, 'error' => 'invalid'];
        }
        if ($row['expires_at'] !== null && new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable()) {
            return ['ok' => false, 'promo' => null, 'error' => 'expired'];
        }
        if ($row['scope'] !== 'both' && $row['scope'] !== $scope) {
            return ['ok' => false, 'promo' => null, 'error' => 'scope'];
        }
        if ($row['max_uses'] !== null && $this->repository->usageCount((int) $row['id']) >= (int) $row['max_uses']) {
            return ['ok' => false, 'promo' => null, 'error' => 'exhausted'];
        }

        return [
            'ok'    => true,
            'promo' => [
                'id'    => (int) $row['id'],
                'code'  => $row['code'],
                'kind'  => $row['kind'],
                'value' => (float) $row['value'],
            ],
            'error' => null,
        ];
    }

    public function errorMessage(string $error): string
    {
        return match ($error) {
            'invalid'   => 'Code promo invalide.',
            'expired'   => 'Ce code promo a expiré.',
            'exhausted' => 'Ce code promo a atteint sa limite d\'utilisation.',
            'scope'     => 'Ce code promo n\'est pas valable pour cette formule.',
            default     => 'Code promo invalide.',
        };
    }
}
