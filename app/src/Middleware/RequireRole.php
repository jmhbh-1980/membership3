<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\Auth\AuthService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route guard. Redirects anonymous users to the login page; renders a 403
 * for authenticated users lacking the required role. Admins may access
 * member routes, not the reverse.
 */
final class RequireRole implements MiddlewareInterface
{
    public function __construct(
        private readonly string $role,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $user = AuthService::currentUser();

        if ($user === null) {
            return $this->responseFactory->createResponse(302)
                ->withHeader('Location', '/connexion');
        }

        $allowed = $user['role'] === $this->role
            || ($this->role === AuthService::ROLE_MEMBER && $user['role'] === AuthService::ROLE_ADMIN);

        if (!$allowed) {
            $response = $this->responseFactory->createResponse(403);
            $response->getBody()->write('Accès refusé.');
            return $response;
        }

        return $handler->handle($request->withAttribute('user', $user));
    }
}
