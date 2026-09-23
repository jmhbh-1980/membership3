<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\AdminBoards;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\RoleResolver;
use App\Service\PricingService;
use App\Service\RenewalService;
use App\Service\Season;
use App\Support\Db;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The two BJ-driven boards and their dashboard badges share one rule: only
 * members of the season in progress. Pinned against a stubbed Balle Jaune so
 * it runs offline — including the paging, since a single page silently
 * stopped at 200 and hid 36 licences before.
 */
final class AdminBoardsTest extends TestCase
{
    private function boards(array $users): AdminBoards
    {
        $bj = new class ($users) extends BalleJauneClient {
            public function __construct(private readonly array $users)
            {
            }

            public function get(string $path, array $query = []): array
            {
                if ($path === 'roles') {
                    return ['roles' => [['name' => 'Visiteur', 'acl_id' => 7]]];
                }
                $page = array_slice($this->users, (int) ($query['offset'] ?? 0), (int) $query['limit']);
                return ['users' => $page, 'total' => count($this->users)];
            }
        };
        $db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $renewals = new RenewalService($db, new PricingService(dirname(__DIR__, 2) . '/pricing_data'));

        return new AdminBoards($bj, new RoleResolver($bj), $renewals);
    }

    private static function member(int $id, string $subscriptionDateEnd): array
    {
        return ['user_id' => $id, 'subscription_date_end' => $subscriptionDateEnd];
    }

    public function testOnlyMembersOfTheSeasonInProgressAreListed(): void
    {
        $season = Season::fromDate(new DateTimeImmutable());
        $boards = $this->boards([
            self::member(1, $season->next()->sept15()->format('Y-m-d')),  // renewed for this season
            self::member(2, $season->sept15()->modify('-2 days')->format('Y-m-d')), // last season, e.g. 13 Sept
            self::member(3, $season->sept15()->format('Y-m-d')),          // exactly 15 Sept: still last season
            self::member(4, $season->sept15()->modify('+50 days')->format('Y-m-d')), // installment 1 paid
            self::member(5, ''),
            self::member(6, '0000-00-00'),
        ]);

        self::assertSame([1, 4], array_column($boards->licencesToRegister(), 'user_id'));
        self::assertSame([1, 4], array_column($boards->awaitingShoesCheck(), 'user_id'));
    }

    public function testEveryPageIsRead(): void
    {
        $current = Season::fromDate(new DateTimeImmutable())->next()->sept15()->format('Y-m-d');
        $users = array_map(static fn (int $id): array => self::member($id, $current), range(1, 450));

        self::assertCount(450, $this->boards($users)->licencesToRegister());
    }
}
