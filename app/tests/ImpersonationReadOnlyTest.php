<?php

declare(strict_types=1);

namespace App\Tests;

use App\Middleware\ImpersonationReadOnly;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\PhpRenderer;

/**
 * Pure session/method/path logic — no Balle Jaune dependency, unlike
 * AuthService::login() itself (see AuthServiceTest's docblock for why that
 * one isn't unit-tested here either).
 */
final class ImpersonationReadOnlyTest extends TestCase
{
    private ImpersonationReadOnly $middleware;
    /** @var RequestHandlerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private RequestHandlerInterface $handler;

    protected function setUp(): void
    {
        $renderer = new PhpRenderer(dirname(__DIR__) . '/templates');
        $renderer->setLayout('layout/base.php');
        $renderer->addAttribute('clubName', 'Bad & Squash');
        $this->middleware = new ImpersonationReadOnly($renderer, new ResponseFactory());
        $this->handler = $this->createMock(RequestHandlerInterface::class);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function request(string $method, string $path): Request
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path);
    }

    public function testNotImpersonatingLetsPostThrough(): void
    {
        $marker = (new ResponseFactory())->createResponse(200);
        $this->handler->expects(self::once())->method('handle')->willReturn($marker);

        $result = $this->middleware->process($this->request('POST', '/espace/credits/checkout'), $this->handler);

        self::assertSame($marker, $result);
    }

    public function testImpersonatingLetsGetThrough(): void
    {
        $_SESSION['impersonator'] = ['bj_user_id' => 1, 'email' => 'admin@example.invalid', 'firstname' => 'A', 'lastname' => 'B'];
        $marker = (new ResponseFactory())->createResponse(200);
        $this->handler->expects(self::once())->method('handle')->willReturn($marker);

        $result = $this->middleware->process($this->request('GET', '/espace/credits'), $this->handler);

        self::assertSame($marker, $result);
    }

    public function testImpersonatingBlocksPostToOtherPaths(): void
    {
        $_SESSION['impersonator'] = ['bj_user_id' => 1, 'email' => 'admin@example.invalid', 'firstname' => 'A', 'lastname' => 'B'];
        $this->handler->expects(self::never())->method('handle');

        $result = $this->middleware->process($this->request('POST', '/espace/credits/checkout'), $this->handler);

        self::assertSame(403, $result->getStatusCode());
    }

    public function testImpersonatingLetsPostToExitPathThrough(): void
    {
        $_SESSION['impersonator'] = ['bj_user_id' => 1, 'email' => 'admin@example.invalid', 'firstname' => 'A', 'lastname' => 'B'];
        $marker = (new ResponseFactory())->createResponse(200);
        $this->handler->expects(self::once())->method('handle')->willReturn($marker);

        $result = $this->middleware->process($this->request('POST', '/voir-comme/quitter'), $this->handler);

        self::assertSame($marker, $result);
    }
}
