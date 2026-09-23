<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\ApplicationRepository;
use App\Repository\OrderRepository;
use App\Service\LicenceKinds;
use App\Service\PricingService;
use App\Service\RenewalService;
use App\Support\Db;
use PHPUnit\Framework\TestCase;

/**
 * Which licence the /admin/licences board says each flagged member needs. It
 * must agree with what fulfillment wrote in their Balle Jaune notes, so each
 * rule fulfillment applies is pinned here: waived licence, Pack été, then the
 * audience/competitor kind — and, for someone the app never registered, only
 * what their BJ subscription name states outright.
 *
 * Integration test against the dev MySQL, same convention as CoupleLinksTest.
 */
final class LicenceKindsTest extends TestCase
{
    private const int SEASON = 2026; // a season with a published price list, so audiences resolve
    private const int MEMBER = 999999701;
    private const int PARTNER = 999999702;
    private const string EMAIL_PREFIX = 'licence-kinds-test-';

    private LicenceKinds $kinds;
    private ApplicationRepository $applications;
    private OrderRepository $orders;
    private RenewalService $renewals;
    private Db $db;

    protected function setUp(): void
    {
        $this->db = new Db(['host' => '127.0.0.1', 'port' => 3307, 'name' => 'membership', 'user' => 'membership', 'password' => 'membership']);
        $pricing = new PricingService(dirname(__DIR__, 2) . '/pricing_data');
        $this->kinds = new LicenceKinds($pricing, $this->db);
        $this->applications = new ApplicationRepository($this->db);
        $this->orders = new OrderRepository($this->db);
        $this->renewals = new RenewalService($this->db, $pricing);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM orders WHERE email LIKE ?')->execute([self::EMAIL_PREFIX . '%']);
        $pdo->prepare('DELETE FROM applications WHERE email LIKE ?')->execute([self::EMAIL_PREFIX . '%']);
        $pdo->prepare('DELETE FROM member_formulas WHERE bj_user_id IN (?, ?)')->execute([self::MEMBER, self::PARTNER]);
    }

    private function kindOf(int $bjUserId, string $bjSubscriptionName = ''): array
    {
        return $this->kinds->forMembers([['user_id' => $bjUserId, 'subscription_id' => 7]], [7 => $bjSubscriptionName])[$bjUserId];
    }

    private function renewal(array $meta, string $type = 'heures-pleines', bool $competitor = false, int $season = self::SEASON): int
    {
        $order = $this->orders->create('renewal', null, self::MEMBER, self::EMAIL_PREFIX . 'o@example.org', 100.0, [], $meta);
        $this->renewals->recordFormula($season, self::MEMBER, $type, !empty($meta['isCouple']), $competitor, 0, 0, (int) $order['id']);
        return (int) $order['id'];
    }

    private function join(array $appFields, array $personFields): void
    {
        $app = $this->applications->create(self::EMAIL_PREFIX . bin2hex(random_bytes(4)) . '@example.org', self::SEASON);
        $id = (int) $app['id'];
        $this->applications->update($id, $appFields);
        $this->applications->savePerson($id, 1, ['firstname' => 'Test', 'lastname' => 'LICENCE', 'birthdate' => '1990-01-01']);
        $this->applications->setPersonBjUserId($id, 1, self::MEMBER);
        if ($personFields !== []) {
            $this->db->pdo()->prepare('UPDATE application_people SET licence_removed = ?, licence_removal_reason = ? WHERE application_id = ?')
                ->execute([(int) $personFields['licence_removed'], $personFields['licence_removal_reason'], $id]);
        }
        $order = $this->orders->create('join', $id, 0, self::EMAIL_PREFIX . 'o@example.org', 100.0, []);
        $this->renewals->recordFormula(self::SEASON, self::MEMBER, (string) ($appFields['subscription_type'] ?? 'heures-pleines'), false, false, 0, 0, (int) $order['id']);
    }

    public function testARenewalIsPassFederaleOrJeuneByAudienceAndCompetitorStatus(): void
    {
        $this->renewal([], 'heures-pleines', competitor: false);
        self::assertSame('pass', $this->kindOf(self::MEMBER)['kind']);

        $this->renewal([], 'heures-creuses', competitor: true);
        self::assertSame('federale', $this->kindOf(self::MEMBER)['kind']);

        $this->renewal([], 'jeune', competitor: true);
        self::assertSame('jeune', $this->kindOf(self::MEMBER)['kind'], 'a Jeune is always licence jeune, competitor or not');
    }

    public function testAPackEteRenewalNeedsASummerLicence(): void
    {
        $this->renewal(['lateSettlement' => true], 'heures-pleines', competitor: true);

        self::assertSame('ete', $this->kindOf(self::MEMBER)['kind']);
    }

    public function testAWaivedLicenceIsReportedWithItsReasonForPayerAndPartnerAlike(): void
    {
        $orderId = $this->renewal([
            'isCouple' => true,
            'licenceRemoved' => false,
            'partnerLicenceRemoved' => true, 'partnerLicenceRemovalReason' => 'Licenciée à Colombes',
        ]);
        $this->renewals->recordFormula(self::SEASON, self::PARTNER, 'heures-pleines', true, false, 0, self::MEMBER, $orderId);

        self::assertSame('pass', $this->kindOf(self::MEMBER)['kind'], 'the payer kept their licence');
        self::assertSame(['kind' => 'retiree', 'detail' => 'Licenciée à Colombes'], $this->kindOf(self::PARTNER));
    }

    public function testAJoinReadsSummerPackAndWaiverFromTheApplication(): void
    {
        $this->join(['subscription_type' => 'heures-pleines', 'summer_pack' => 1], []);
        self::assertSame('ete', $this->kindOf(self::MEMBER)['kind']);

        $this->cleanUp();
        $this->join(['subscription_type' => 'heures-pleines'], ['licence_removed' => true, 'licence_removal_reason' => 'Déjà licencié']);
        self::assertSame(['kind' => 'retiree', 'detail' => 'Déjà licencié'], $this->kindOf(self::MEMBER));
    }

    public function testTheLatestSeasonWins(): void
    {
        $this->renewal([], 'jeune', season: self::SEASON - 1);
        $this->renewal([], 'heures-pleines', competitor: true, season: self::SEASON);

        self::assertSame('federale', $this->kindOf(self::MEMBER)['kind']);
    }

    public function testAMemberTheAppNeverRegisteredOnlyGetsWhatTheirSubscriptionNameStates(): void
    {
        self::assertSame('jeune', $this->kindOf(self::MEMBER, '_Abonnement Individuel Jeune')['kind']);
        self::assertSame('federale', $this->kindOf(self::MEMBER, 'Abonnement Garennois - Individuel Compétiteur Midi (réinscription)')['kind']);
        self::assertSame('pass', $this->kindOf(self::MEMBER, 'Abonnement Non-Garennois - Couple Loisir Heures Pleines (réinscription)')['kind']);
        self::assertSame('d\'après l\'abonnement', $this->kindOf(self::MEMBER, '_Abonnement Individuel Jeune')['detail']);

        // A "_" formula says nothing about competitor status: no guess.
        self::assertSame(['kind' => 'inconnue', 'detail' => ''], $this->kindOf(self::MEMBER, '_Abonnement Individuel - Heures Pleines'));
        self::assertSame(['kind' => 'inconnue', 'detail' => ''], $this->kindOf(self::MEMBER, ''));
    }
}
