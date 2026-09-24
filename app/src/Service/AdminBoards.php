<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\RoleResolver;
use App\Service\BalleJaune\SubscriptionResolver;
use DateTimeImmutable;

/**
 * Who belongs on the two Balle Jaune-driven to-do boards: licences to register
 * with the federation, and new members awaiting the shoes check. Shared by the
 * boards themselves and the dashboard badges, so a badge always counts exactly
 * the rows its page shows.
 *
 * Both keep only members of the season in progress. A BJ flag or a Visiteur
 * role left over from a past season (member never came back, or was ticked off
 * in BJ without clearing the flag) is not work for this season, and listing it
 * buried the real to-do: at the 2026-2027 rollover, 83 of the 224 flagged
 * licences belonged to members who had not renewed.
 *
 * The one exception is the shoes check for ticket-formula members: they have
 * no season at all (Balle Jaune holds them with no membership dates — see
 * PricingService::TICKETS), so a paid Visiteur on a ticket formula always
 * still needs activating.
 */
final class AdminBoards
{
    private const int PAGE = 200; // BJ's maximum page size

    public function __construct(
        private readonly BalleJauneClient $bj,
        private readonly RoleResolver $roles,
        private readonly RenewalService $renewals,
        private readonly SubscriptionResolver $subscriptions,
    ) {
    }

    /** @return array[] BJ users flagged "licence not registered yet" who are members this season */
    public function licencesToRegister(): array
    {
        return $this->currentSeasonOnly($this->allUsers(['keywords' => ['flag']]));
    }

    /** @return array[] paid Visiteur accounts (not yet activated as Membre) who are members this season */
    public function awaitingShoesCheck(): array
    {
        return $this->currentSeasonOnly($this->allUsers([
            'roles'    => [$this->roles->idForName('Visiteur')],
            'keywords' => ['subscription-paid'],
        ]), keepTicketMembers: true);
    }

    /** Same coverage rule as renewals: see RenewalService::subscriptionCovers(). */
    private function currentSeasonOnly(array $users, bool $keepTicketMembers = false): array
    {
        $season = Season::fromDate(new DateTimeImmutable());
        $ticketFormulas = $keepTicketMembers ? $this->ticketFormulaIds() : [];
        return array_values(array_filter(
            $users,
            fn (array $u): bool => in_array((int) ($u['subscription_id'] ?? 0), $ticketFormulas, true)
                || $this->renewals->subscriptionCovers((string) ($u['subscription_date_end'] ?? ''), $season),
        ));
    }

    /**
     * Every BJ ticket formula (FORMULE TICKETS-5, -10…), recognised by name the
     * same way the member home page tells a ticket member from a season member.
     *
     * @return int[]
     */
    private function ticketFormulaIds(): array
    {
        $ids = [];
        foreach ($this->subscriptions->map() as $name => $id) {
            if (str_contains($name, 'TICKETS')) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** Every page of a filtered BJ user list — a single page silently stops at 200. */
    private function allUsers(array $filters): array
    {
        $users = [];
        $offset = 0;
        do {
            $data = $this->bj->get('users', ['filters' => json_encode($filters), 'limit' => self::PAGE, 'offset' => $offset]);
            $page = $data['users'] ?? [];
            $users = [...$users, ...$page];
            $offset += self::PAGE;
        } while ($page !== [] && $offset < (int) ($data['total'] ?? 0));
        return $users;
    }
}
