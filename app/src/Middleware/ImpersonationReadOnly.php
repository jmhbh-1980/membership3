<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\PhpRenderer;

/**
 * View-only enforcement for admin "view as member" (see AuthService::
 * startImpersonation()). Global, not per-route: while impersonating, every
 * state-changing request is blocked, public routes included (an
 * impersonating admin navigating straight to e.g. /inscription must be
 * blocked too, not just /espace/*). This one central check is what keeps
 * "view-only" from needing an edit in every member-facing controller.
 */
final class ImpersonationReadOnly implements MiddlewareInterface
{
    private const string EXIT_PATH = '/voir-comme/quitter';

    public function __construct(
        private readonly PhpRenderer $renderer,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $impersonating = ($_SESSION['impersonator'] ?? null) !== null;
        $stateChanging = !in_array($request->getMethod(), ['GET', 'HEAD'], true);
        if (!$impersonating || !$stateChanging || $request->getUri()->getPath() === self::EXIT_PATH) {
            return $handler->handle($request);
        }

        return $this->renderer->render(
            $this->responseFactory->createResponse(403),
            'pages/impersonation_blocked.php',
            ['title' => 'Mode observateur'],
        );
    }
}
