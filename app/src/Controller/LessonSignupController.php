<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Service\BalleJaune\BalleJauneClient;
use App\Service\LessonAddOnService;
use App\Service\PricingService;
use App\Service\SumUpService;
use App\Support\Csrf;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/**
 * Standalone "cours collectifs" add-on for a member who already renewed
 * without it. Same pending→paid→fulfilling→fulfilled loop as
 * CreditsController; fulfillment enrolls the member
 * (FulfillmentService::fulfillLessonAddon). Eligibility (which season, and
 * whether to offer it at all) lives in LessonAddOnService, shared with the
 * member dashboard.
 */
final class LessonSignupController
{
    public function __construct(
        private readonly BalleJauneClient $bj,
        private readonly PricingService $pricing,
        private readonly LessonAddOnService $lessonAddOns,
        private readonly OrderRepository $orders,
        private readonly SumUpService $sumup,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $sessionUser = $request->getAttribute('user');
        $bjUser = $this->bj->get('users/' . $sessionUser['bj_user_id'])['user'];
        $season = LessonAddOnService::targetSeason(new DateTimeImmutable());

        $eligibility = $this->lessonAddOns->eligibility($bjUser, $season);
        $addOn = $eligibility['state'] === 'offer' ? $this->pricing->lessonAddOn($season, new DateTimeImmutable()) : null;

        return $this->renderer->render($response, 'pages/lessons_addon.php', [
            'title'  => 'Cours collectifs',
            'csrf'   => Csrf::token(),
            'state'  => $eligibility['state'],
            'reason' => $eligibility['reason'],
            'season' => $season,
            'addOn'  => $addOn,
            'errors' => [],
        ]);
    }

    public function startCheckout(Request $request, Response $response): Response
    {
        $sessionUser = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        if (!Csrf::validate($body['csrf'] ?? null)) {
            return $response->withStatus(302)->withHeader('Location', '/espace/cours-collectifs');
        }

        $bjUser = $this->bj->get('users/' . $sessionUser['bj_user_id'])['user'];
        $season = LessonAddOnService::targetSeason(new DateTimeImmutable());

        // Re-checked here (not just trusted from the form) so a resubmission or a
        // stale page can't create a second order once the member is enrolled.
        $eligibility = $this->lessonAddOns->eligibility($bjUser, $season);
        if ($eligibility['state'] !== 'offer') {
            return $response->withStatus(302)->withHeader('Location', '/espace/cours-collectifs');
        }

        $addOn = $this->pricing->lessonAddOn($season, new DateTimeImmutable());

        $order = $this->orders->create(
            'lessons',
            null,
            (int) $bjUser['user_id'],
            (string) $bjUser['email'],
            $addOn['amount'],
            [['type' => 'lessons', 'label' => $addOn['label'], 'amount' => $addOn['amount'], 'baseAmount' => $addOn['baseAmount']]],
            [
                'seasonStartYear' => $season->startYear,
                'firstname'       => (string) $bjUser['firstname'],
                'lastname'        => (string) $bjUser['lastname'],
                'email'           => (string) $bjUser['email'],
            ],
        );

        $uri = $request->getUri();
        $returnUrl = $uri->getScheme() . '://' . $uri->getAuthority() . '/paiement/retour/' . $order['checkout_reference'];
        try {
            $checkout = $this->sumup->createCheckout(
                $order['checkout_reference'],
                (float) $order['amount'],
                $addOn['label'] . ' — Bad & Squash',
                $returnUrl,
            );
        } catch (\RuntimeException $e) {
            return $this->renderer->render($response, 'pages/lessons_addon.php', [
                'title'  => 'Cours collectifs',
                'csrf'   => Csrf::token(),
                'state'  => 'offer',
                'reason' => null,
                'season' => $season,
                'addOn'  => $addOn,
                'errors' => [$e->getMessage()],
            ]);
        }

        $this->orders->update((int) $order['id'], ['checkout_id' => $checkout['checkout_id']]);
        return $response->withStatus(302)->withHeader('Location', $checkout['url']);
    }
}
