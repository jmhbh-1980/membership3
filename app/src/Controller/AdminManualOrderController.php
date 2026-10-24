<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\BalleJauneException;
use App\Service\BalleJaune\SubscriptionResolver;
use App\Service\ManualOrderService;
use App\Service\PricingService;
use App\Service\RenewalService;
use App\Service\Season;
use App\Support\Csrf;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Views\PhpRenderer;

/**
 * « Enregistrer un paiement hors application », from the member's page: an
 * admin records what a member paid the club directly and gets the order and
 * its numbered invoice (see ManualOrderService). Two steps on one URL —
 * Calculer shows the grid price line by line, each amount editable; Créer
 * records it. A create whose options no longer match the amounts shown is
 * sent back to the calculation instead of guessing which amount goes where.
 *
 * Balle Jaune is only read here (the member, their partner), never written.
 */
final class AdminManualOrderController
{
    public function __construct(
        private readonly ManualOrderService $manualOrders,
        private readonly PricingService $pricing,
        private readonly RenewalService $renewals,
        private readonly SubscriptionResolver $subscriptions,
        private readonly OrderRepository $orders,
        private readonly BalleJauneClient $bj,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $bjUser = $this->member((int) $args['id']);
        if ($bjUser === null) {
            return $response->withStatus(302)->withHeader('Location', '/admin/membres');
        }
        return $this->render($response, $bjUser, $this->defaults($bjUser), null, [], null);
    }

    public function submit(Request $request, Response $response, array $args): Response
    {
        $bjUser = $this->member((int) $args['id']);
        if ($bjUser === null) {
            return $response->withStatus(302)->withHeader('Location', '/admin/membres');
        }
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/membres/' . (int) $bjUser['user_id'] . '/paiement-hors-appli');
        }

        $form = self::formValues($body);
        try {
            [$input, $payment, $partner] = $this->parse($form, $bjUser);
            $priced = $this->manualOrders->price($bjUser, $input);
        } catch (InvalidArgumentException $e) {
            return $this->render($response, $bjUser, $form, null, [$e->getMessage()], null);
        }
        $refusal = $this->manualOrders->refusal((int) $bjUser['user_id'], $input, $partner !== null ? (int) $partner['user_id'] : 0);
        $priced['signature'] = sha1((string) json_encode($priced['lines']));
        // Amounts typed for another set of lines (other options, another
        // purchase) mean nothing for these: start again from the grid.
        $sameLines = ($body['signature'] ?? '') === $priced['signature'];
        if (!$sameLines) {
            $form['amounts'] = [];
        }

        if (($body['step'] ?? '') !== 'creer' || $refusal !== null) {
            return $this->render($response, $bjUser, $form, $priced, [], $refusal);
        }
        if (!$sameLines) {
            return $this->render($response, $bjUser, $form, $priced,
                ['Les options ont changé depuis le calcul : vérifiez les montants ci-dessous, puis créez la commande.'], null);
        }

        try {
            $result = $this->manualOrders->create($bjUser, $partner, $input, $form['amounts'], $payment,
                (string) $request->getAttribute('user')['email']);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->render($response, $bjUser, $form, $priced, [$e->getMessage()], null);
        }
        return $response->withStatus(302)->withHeader('Location', '/admin/commandes/' . (int) $result['order']['id'] . '?enregistree=1');
    }

    /** Withdraws an order recorded here — only while its invoice is the latest number issued. */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null || !Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/admin/commandes');
        }
        try {
            $this->manualOrders->delete($order, (string) $request->getAttribute('user')['email']);
        } catch (RuntimeException) {
            return $response->withStatus(302)->withHeader('Location', '/admin/commandes/' . (int) $order['id']);
        }
        return $response->withStatus(302)->withHeader('Location',
            '/admin/membres/' . (int) $order['bj_user_id'] . '?commande-supprimee=' . (int) $order['id']);
    }

    // ── Form ─────────────────────────────────────────────────────────────

    private function defaults(array $bjUser): array
    {
        $season = Season::fromDate(new DateTimeImmutable());
        $known = $this->renewals->knownFormula((int) $bjUser['user_id']);
        $bjName = (string) (array_search((int) ($bjUser['subscription_id'] ?? 0), $this->subscriptions->map(), true) ?: '');
        $start = (string) ($bjUser['subscription_date_start'] ?? '');
        $memberBefore = $known !== null || ($start !== '' && !str_starts_with($start, '0000') && $start < $season->start()->format('Y-m-d'));

        return self::formValues([
            'purchase'         => ManualOrderService::MEMBERSHIP,
            'season'           => $season->startYear,
            'payment_date'     => (new DateTimeImmutable())->format('Y-m-d'),
            'subscription'     => $known['subscription_type'] ?? ($bjName !== '' ? $this->pricing->subscriptionKeyForBjName($bjName, $season) : null) ?? '',
            'tarif'            => $memberBefore ? 'renouvellement' : 'premiere',
            'competitor'       => !empty($known['competitor']),
            'couple'           => $this->coupleStatus($bjUser)['isCouple'],
        ]);
    }

    /** The posted form as plain values, for parsing and for re-rendering exactly what was typed. */
    private static function formValues(array $body): array
    {
        $text = static fn (string $k): string => trim((string) ($body[$k] ?? ''));
        return [
            'purchase'                      => $text('purchase') ?: ManualOrderService::MEMBERSHIP,
            'season'                        => (int) ($body['season'] ?? 0),
            'payment_date'                  => $text('payment_date'),
            'effective_date'                => $text('effective_date'),
            // Neither "method" nor "action": a field named like that shadows the
            // form's own property in the browser (assets/action-guard.js reads it).
            'payment_mode'                  => $text('payment_mode'),
            'note'                          => mb_substr($text('note'), 0, 500),
            'subscription'                  => $text('subscription'),
            'tarif'                         => $text('tarif') === 'premiere' ? 'premiere' : 'renouvellement',
            'competitor'                    => !empty($body['competitor']),
            'licence_removed'               => !empty($body['licence_removed']),
            'licence_removal_reason'        => mb_substr($text('licence_removal_reason'), 0, 500),
            'lessons'                       => max(0, (int) ($body['lessons'] ?? 0)),
            'summer_pack'                   => !empty($body['summer_pack']),
            'couple'                        => !empty($body['couple']),
            'partner_competitor'            => !empty($body['partner_competitor']),
            'partner_licence_removed'       => !empty($body['partner_licence_removed']),
            'partner_licence_removal_reason' => mb_substr($text('partner_licence_removal_reason'), 0, 500),
            'packs'                         => max(1, (int) ($body['packs'] ?? 1)),
            'amounts'                       => array_map(static fn ($v): string => trim((string) $v), array_values((array) ($body['amounts'] ?? []))),
        ];
    }

    /**
     * @return array{0: array, 1: array, 2: ?array} the service input, the payment, the partner's BJ user
     * @throws InvalidArgumentException
     */
    private function parse(array $form, array $bjUser): array
    {
        if (!isset(ManualOrderService::PURCHASES[$form['purchase']])) {
            throw new InvalidArgumentException('Choisissez ce qui a été acheté.');
        }
        $season = $this->seasonFrom($form['season']);
        $today = new DateTimeImmutable('today');
        $paymentDate = self::date($form['payment_date']);
        if ($paymentDate === null) {
            throw new InvalidArgumentException('Indiquez la date du paiement.');
        }
        if ($paymentDate > $today) {
            throw new InvalidArgumentException('La date du paiement ne peut pas être dans le futur.');
        }
        $effectiveDate = $form['effective_date'] === '' ? $paymentDate : self::date($form['effective_date']);
        if ($effectiveDate === null) {
            throw new InvalidArgumentException('La date d\'effet n\'est pas une date valide.');
        }

        $input = ['purchase' => $form['purchase'], 'season' => $season, 'effectiveDate' => $effectiveDate, 'packs' => $form['packs']];
        $partner = null;
        if ($form['purchase'] === ManualOrderService::MEMBERSHIP) {
            if ($form['subscription'] === '') {
                throw new InvalidArgumentException('Choisissez la formule.');
            }
            foreach (['licence' => '', 'partner_licence' => ' du/de la conjoint(e)'] as $prefix => $whose) {
                if ($form[$prefix . '_removed'] && $form[$prefix . '_removal_reason'] === '') {
                    throw new InvalidArgumentException('Indiquez le motif du retrait de la licence' . $whose . '.');
                }
            }
            if ($form['couple']) {
                $partnerId = $this->coupleStatus($bjUser)['partnerBjUserId'];
                $partner = $partnerId > 0 ? $this->member($partnerId) : null;
                if ($partner === null) {
                    throw new InvalidArgumentException('Aucun(e) conjoint(e) n\'est rattaché(e) à ce membre dans Balle Jaune (champ custom3) : '
                        . 'impossible d\'enregistrer une adhésion en couple.');
                }
            }
            $input += [
                'subscriptionType'            => $form['subscription'],
                'premiere'                    => $form['tarif'] === 'premiere',
                'competitor'                  => $form['competitor'],
                'licenceRemoved'              => $form['licence_removed'],
                'licenceRemovalReason'        => $form['licence_removal_reason'],
                'lessons'                     => $form['lessons'],
                'summerPack'                  => $form['summer_pack'],
                'isCouple'                    => $form['couple'],
                'partnerCompetitor'           => $form['partner_competitor'],
                'partnerLicenceRemoved'       => $form['partner_licence_removed'],
                'partnerLicenceRemovalReason' => $form['partner_licence_removal_reason'],
            ];
        }
        $payment = ['date' => $paymentDate, 'method' => $form['payment_mode'], 'note' => $form['note']];
        return [$input, $payment, $partner];
    }

    // ── Rendering ────────────────────────────────────────────────────────

    private function render(Response $response, array $bjUser, array $form, ?array $priced, array $errors, ?array $refusal): Response
    {
        $seasons = $this->seasons();
        $season = $this->seasonOrCurrent($form['season']);
        $residences = $this->manualOrders->residences($bjUser, $season);
        $couple = $this->coupleStatus($bjUser);
        $partner = $couple['partnerBjUserId'] > 0 ? $this->member($couple['partnerBjUserId']) : null;
        $pack = $this->pricing->hasCatalogue($season) ? $this->pricing->ticketPack($season) : null;

        return $this->renderer->render($response, 'pages/admin_manual_order.php', [
            'title'         => 'Paiement hors application',
            'csrf'          => Csrf::token(),
            'bjUser'        => $bjUser,
            'form'          => $form,
            'seasons'       => $seasons,
            'season'        => $season,
            'residences'    => $residences,
            'subscriptions' => $this->pricing->hasCatalogue($season) ? $this->pricing->subscriptionsFor($residences['pricingResidence'], $season) : [],
            'partner'       => $partner,
            'pack'          => $pack,
            'priced'        => $priced,
            'errors'        => $errors,
            'refusal'       => $refusal,
        ]);
    }

    /** @return array{isCouple: bool, partnerBjUserId: int} */
    private function coupleStatus(array $bjUser): array
    {
        return $this->renewals->resolveCoupleStatus($bjUser, $this->renewals->knownFormula((int) $bjUser['user_id']), null);
    }

    /** The current season and its neighbours, when their price list is published. @return list<Season> */
    private function seasons(): array
    {
        $current = Season::fromDate(new DateTimeImmutable());
        return array_values(array_filter(
            [new Season($current->startYear - 1), $current, $current->next()],
            fn (Season $s): bool => $this->pricing->hasCatalogue($s),
        ));
    }

    /** @throws InvalidArgumentException */
    private function seasonFrom(int $startYear): Season
    {
        foreach ($this->seasons() as $season) {
            if ($season->startYear === $startYear) {
                return $season;
            }
        }
        throw new InvalidArgumentException('Choisissez une saison dont les tarifs sont publiés.');
    }

    private function seasonOrCurrent(int $startYear): Season
    {
        try {
            return $this->seasonFrom($startYear);
        } catch (InvalidArgumentException) {
            return Season::fromDate(new DateTimeImmutable());
        }
    }

    private function member(int $bjUserId): ?array
    {
        if ($bjUserId <= 0) {
            return null;
        }
        try {
            return $this->bj->get('users/' . $bjUserId)['user'] ?? null;
        } catch (BalleJauneException) {
            return null;
        }
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
