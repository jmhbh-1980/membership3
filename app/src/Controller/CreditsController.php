<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AuditLogRepository;
use App\Repository\OrderRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\SubscriptionResolver;
use App\Service\PaymentSettlementService;
use App\Service\PricingService;
use App\Service\ReglementInterieurService;
use App\Service\Season;
use App\Service\ShoesPolicyImageService;
use App\Service\SumUpService;
use App\Support\Csrf;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/**
 * Play-credit top-up for ticket members (single pack: 5 credits). On paid
 * order, fulfillment adds the credits to BJ book_card_tickets.
 */
final class CreditsController
{
    public function __construct(
        private readonly BalleJauneClient $bj,
        private readonly SubscriptionResolver $subscriptions,
        private readonly PricingService $pricing,
        private readonly OrderRepository $orders,
        private readonly SumUpService $sumup,
        private readonly PaymentSettlementService $settlement,
        private readonly ReglementInterieurService $reglement,
        private readonly ShoesPolicyImageService $shoesPolicyImage,
        private readonly AuditLogRepository $auditLog,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $sessionUser = $request->getAttribute('user');
        $bjUser = $this->bj->get('users/' . $sessionUser['bj_user_id'])['user'];
        $pack = $this->pricing->ticketPack(Season::fromDate(new DateTimeImmutable()));

        return $this->renderer->render($response, 'pages/credits.php', [
            'title'   => 'Crédits de jeu',
            'csrf'    => Csrf::token(),
            'user'    => $bjUser,
            'pack'    => $pack,
            'reglementHtml' => $this->reglement->html(),
            'shoesPolicyImageUrl' => $this->shoesPolicyImage->url(),
            'errors'  => [],
        ]);
    }

    public function startCheckout(Request $request, Response $response): Response
    {
        $sessionUser = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/espace/credits');
        }

        $bjUser = $this->bj->get('users/' . $sessionUser['bj_user_id'])['user'];
        $pack = $this->pricing->ticketPack(Season::fromDate(new DateTimeImmutable()));

        $consentErrors = [];
        if (empty($body['reglement_accepted'])) {
            $consentErrors[] = 'Merci d\'accepter le règlement intérieur pour continuer.';
        }
        if (empty($body['shoes_policy_accepted'])) {
            $consentErrors[] = 'Merci de confirmer avoir pris connaissance des règles chaussures pour continuer.';
        }
        if ($consentErrors !== []) {
            return $this->renderer->render($response, 'pages/credits.php', [
                'title'  => 'Crédits de jeu',
                'csrf'   => Csrf::token(),
                'user'   => $bjUser,
                'pack'   => $pack,
                'reglementHtml' => $this->reglement->html(),
                'shoesPolicyImageUrl' => $this->shoesPolicyImage->url(),
                'errors' => $consentErrors,
            ]);
        }
        $this->auditLog->log((string) $bjUser['email'], 'reglement_interieur.accepted', 'bj_user', (string) $bjUser['user_id'], ['kind' => 'credits']);
        $this->auditLog->log((string) $bjUser['email'], 'shoes_policy.accepted', 'bj_user', (string) $bjUser['user_id'], ['kind' => 'credits']);

        $resumeUrl = $this->settlement->resumeIfOpen($this->orders->findOpenOrderByBjUser((int) $bjUser['user_id'], 'credits'));
        if ($resumeUrl !== null) {
            return $response->withStatus(302)->withHeader('Location', $resumeUrl);
        }

        $order = $this->orders->create(
            'credits',
            null,
            (int) $bjUser['user_id'],
            (string) $bjUser['email'],
            (float) $pack['price'],
            [['type' => 'tickets', 'label' => $pack['label'], 'amount' => (float) $pack['price']]],
            ['tickets' => (int) $pack['tickets']],
        );

        $uri = $request->getUri();
        $returnUrl = $uri->getScheme() . '://' . $uri->getAuthority() . '/paiement/retour/' . $order['checkout_reference'];
        try {
            $checkout = $this->sumup->createCheckout(
                $order['checkout_reference'],
                (float) $order['amount'],
                $pack['label'] . ' — Bad & Squash',
                $returnUrl,
            );
        } catch (\RuntimeException $e) {
            return $this->renderer->render($response, 'pages/credits.php', [
                'title'  => 'Crédits de jeu',
                'csrf'   => Csrf::token(),
                'user'   => $bjUser,
                'pack'   => $pack,
                'reglementHtml' => $this->reglement->html(),
                'shoesPolicyImageUrl' => $this->shoesPolicyImage->url(),
                'errors' => [$e->getMessage()],
            ]);
        }

        $this->orders->update((int) $order['id'], ['checkout_id' => $checkout['checkout_id']]);
        return $response->withStatus(302)->withHeader('Location', $checkout['url']);
    }
}
