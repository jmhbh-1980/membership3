<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\BalleJaune\SubscriptionResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

final class MemberController
{
    public function __construct(
        private readonly BalleJauneClient $bj,
        private readonly SubscriptionResolver $subscriptions,
        private readonly OrderRepository $orders,
        private readonly PhpRenderer $renderer,
    ) {
    }

    /** Member dashboard: live identity/membership/credits from Balle Jaune. */
    public function dashboard(Request $request, Response $response): Response
    {
        $sessionUser = $request->getAttribute('user');
        $bjUser = $this->bj->get('users/' . $sessionUser['bj_user_id'])['user'] ?? [];

        $subscriptionName = '';
        $subscriptionId = (int) ($bjUser['subscription_id'] ?? 0);
        if ($subscriptionId > 0) {
            $subscriptionName = array_search($subscriptionId, $this->subscriptions->map(), true) ?: '';
        }

        $paidOrder = ($bjUser['subscription_paid'] ?? 0)
            ? $this->orders->latestFulfilledForBjUser((int) $sessionUser['bj_user_id'])
            : null;

        return $this->renderer->render($response, 'pages/member_home.php', [
            'title'            => 'Mon espace',
            'user'             => $bjUser,
            'subscriptionName' => $subscriptionName,
            'paidAt'           => $paidOrder['fulfilled_at'] ?? null,
        ]);
    }
}
