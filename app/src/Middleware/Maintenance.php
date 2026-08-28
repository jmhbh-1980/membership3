<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repository\SettingsRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\PhpRenderer;

/**
 * Deploy-time maintenance gate (see bin/maintenance.php and the deploy
 * skill). When the `maintenance_mode` setting is on, every request gets the
 * maintenance page instead of reaching a controller — admins included, no
 * bypass, since the whole point is that no write path (member or admin) can
 * run while a deploy is in flight. /sante stays reachable: the deploy
 * script's own health check needs it to confirm the new code actually works
 * before flipping maintenance back off.
 */
final class Maintenance implements MiddlewareInterface
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly PhpRenderer $renderer,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if ($request->getUri()->getPath() === '/sante' || !$this->settings->isEnabled('maintenance_mode')) {
            return $handler->handle($request);
        }

        $response = $this->renderer->render(
            $this->responseFactory->createResponse(503),
            'pages/maintenance.php',
            ['title' => 'Maintenance en cours'],
        );

        return $response->withHeader('Retry-After', '120');
    }
}
