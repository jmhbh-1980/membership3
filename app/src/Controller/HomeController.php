<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

final class HomeController
{
    public function __construct(private readonly PhpRenderer $renderer)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->renderer->render($response, 'pages/home.php', [
            'title' => 'Accueil',
        ]);
    }
}
