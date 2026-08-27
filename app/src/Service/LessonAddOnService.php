<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OrderRepository;
use App\Service\BalleJaune\SubscriptionResolver;
use DateTimeImmutable;

/**
 * Eligibility for the standalone "cours collectifs" add-on
 * (LessonSignupController) — shared with the member dashboard so it only
 * shows the entry point when there's actually something to do.
 */
final class LessonAddOnService
{
    public function __construct(
        private readonly SubscriptionResolver $subscriptions,
        private readonly PricingService $pricing,
        private readonly RenewalService $renewals,
        private readonly OrderRepository $orders,
    ) {
    }

    /**
     * No lessons run in July/August: past 30 June, the add-on is for next
     * season instead (lessonAddOn() naturally prices that at full rate,
     * since $asOf then falls before that season's own +1-month prorata
     * threshold). Eligibility separately gates this on that season's price
     * list actually being published.
     */
    public static function targetSeason(DateTimeImmutable $today): Season
    {
        $current = Season::fromDate($today);
        $cutoff = new DateTimeImmutable(($current->startYear + 1) . '-07-01');
        return $today >= $cutoff ? $current->next() : $current;
    }

    /** @return array{state: 'offer'|'already_enrolled'|'ineligible', reason: ?string} */
    public function eligibility(array $bjUser, Season $season): array
    {
        if (!$this->pricing->hasCatalogue($season)) {
            return ['state' => 'ineligible', 'reason' => 'Le tarif de la saison ' . $season->label() . ' n\'est pas encore publié — vous serez informé·e dès son ouverture.'];
        }

        $subscriptionName = '';
        $subscriptionId = (int) ($bjUser['subscription_id'] ?? 0);
        if ($subscriptionId > 0) {
            $subscriptionName = array_search($subscriptionId, $this->subscriptions->map(), true) ?: '';
        }

        if (str_contains($subscriptionName, 'TICKETS')) {
            return ['state' => 'ineligible', 'reason' => 'Les cours collectifs ne sont pas proposés avec la formule tickets.'];
        }

        if (!$this->renewals->subscriptionCovers((string) ($bjUser['subscription_date_end'] ?? ''), new DateTimeImmutable())) {
            return ['state' => 'ineligible', 'reason' => 'Votre adhésion doit être à jour pour ajouter les cours collectifs. Renouvelez-la d\'abord.'];
        }

        $subscriptionKey = $subscriptionName !== '' ? $this->pricing->subscriptionKeyForBjName($subscriptionName, $season) : null;
        $audience = $subscriptionKey !== null ? $this->pricing->subscription($subscriptionKey, $season)['audience'] : null;
        if ($audience === 'jeune') {
            return ['state' => 'ineligible', 'reason' => 'Les cours collectifs sont déjà inclus dans votre formule Jeune.'];
        }

        $bjUserId = (int) $bjUser['user_id'];
        $lastOrder = $this->orders->latestFulfilledForBjUser($bjUserId);
        if ($lastOrder !== null) {
            $meta = json_decode((string) ($lastOrder['meta'] ?? '{}'), true) ?: [];
            // Scoped to the targeted season: a Pack été settlement only excludes
            // lessons for the season it actually paid for, not a later one.
            if (!empty($meta['lateSettlement']) && (int) ($meta['seasonStartYear'] ?? -1) === $season->startYear) {
                return ['state' => 'ineligible', 'reason' => 'Les cours collectifs ne sont pas proposés avec le Pack été.'];
            }
        }

        if ($this->orders->isEnrolledInLessons($bjUserId, $season->startYear)) {
            return ['state' => 'already_enrolled', 'reason' => null];
        }

        return ['state' => 'offer', 'reason' => null];
    }
}
