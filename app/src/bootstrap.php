<?php

declare(strict_types=1);

use App\Support\Db;
use App\Support\Logger;
use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;

date_default_timezone_set('Europe/Paris');

$settings = require dirname(__DIR__) . '/config/settings.php';

$container = new Container();
$container->set('settings', $settings);
$container->set(Logger::class, fn () => new Logger($settings['paths']['log_file']));
$container->set(Db::class, fn () => new Db($settings['db']));
$container->set(\App\Controller\HealthController::class, fn (Container $c) => new \App\Controller\HealthController(
    $c->get(Db::class),
    $c->get(Logger::class),
    $settings,
));
$container->set(\App\Service\BalleJaune\BalleJauneClient::class, fn (Container $c) => new \App\Service\BalleJaune\BalleJauneClient(
    $settings['ballejaune']['base_url'],
    $settings['ballejaune']['api_key'] ?? '',
    $c->get(Logger::class),
));
$container->set(\App\Service\PricingService::class, fn () => new \App\Service\PricingService(
    $settings['paths']['pricing_data'],
    $settings['club']['city_zip'],
));
$container->set(\App\Controller\AdminPricingController::class, fn (Container $c) => new \App\Controller\AdminPricingController(
    $c->get(\App\Service\BalleJaune\SubscriptionResolver::class),
    $c->get(\App\Service\PricingCsvCodec::class),
    $c->get(\App\Service\PricingFileWriter::class),
    $c->get(PhpRenderer::class),
    $c->get(Db::class),
    $c->get(Logger::class),
    $settings['paths']['pricing_data'],
));
$container->set(\App\Service\UploadService::class, fn (Container $c) => new \App\Service\UploadService(
    $settings['paths']['uploads'],
    $c->get(\App\Repository\ApplicationRepository::class),
));
$container->set(\App\Service\AttestationPdfService::class, fn () => new \App\Service\AttestationPdfService(
    $settings['paths']['uploads'],
));
$container->set(\App\Service\InvoiceDescriptions::class, fn () => new \App\Service\InvoiceDescriptions(
    $settings['paths']['pricing_data'],
));
$container->set(\App\Service\InvoicePdfService::class, fn () => new \App\Service\InvoicePdfService(
    $settings['paths']['uploads'],
    $settings['club'],
    dirname(__DIR__) . '/assets/logo.png',
));
$container->set(\App\Service\InvoiceService::class, fn (Container $c) => new \App\Service\InvoiceService(
    $c->get(\App\Repository\InvoiceRepository::class),
    $c->get(\App\Service\InvoiceNumberService::class),
    $c->get(\App\Service\OrderBreakdownService::class),
    $c->get(\App\Service\InvoiceLineComposer::class),
    $c->get(\App\Service\InvoicePdfService::class),
    $settings['paths']['uploads'],
    $c->get(Logger::class),
));
$container->set(\App\Service\SumUpService::class, fn (Container $c) => new \App\Service\SumUpService(
    $settings['sumup'],
    $c->get(Logger::class),
));
$container->set(\App\Controller\PaymentController::class, fn (Container $c) => new \App\Controller\PaymentController(
    $c->get(\App\Repository\ApplicationRepository::class),
    $c->get(\App\Repository\OrderRepository::class),
    $c->get(\App\Service\PricingService::class),
    $c->get(\App\Service\PromoCodeService::class),
    $c->get(\App\Service\SumUpService::class),
    $c->get(\App\Service\FulfillmentService::class),
    $c->get(\App\Service\OrderBreakdownService::class),
    $c->get(PhpRenderer::class),
    $c->get(Logger::class),
    $settings['debug'],
));
$container->set(\App\Service\Mailer::class, fn (Container $c) => new \App\Service\Mailer(
    $settings['smtp'],
    $c->get(Db::class),
    $c->get(Logger::class),
));
$container->set(\App\Controller\AuthController::class, fn (Container $c) => new \App\Controller\AuthController(
    $c->get(\App\Service\Auth\AuthService::class),
    $c->get(\App\Service\BalleJaune\BalleJauneClient::class),
    $c->get(\App\Service\Mailer::class),
    $c->get(PhpRenderer::class),
    $c->get(Logger::class),
    $settings['debug'],
));
$container->set(PhpRenderer::class, function (Container $c) use ($settings) {
    $renderer = new PhpRenderer($settings['paths']['templates']);
    $renderer->setLayout('layout/base.php');
    $renderer->addAttribute('clubName', $settings['club']['name']);
    $renderer->addAttribute('bugReportModeEnabled', $c->get(\App\Repository\SettingsRepository::class)->isEnabled('bug_report_mode'));
    return $renderer;
});

AppFactory::setContainer($container);
$app = AppFactory::create();

// Sessions — secure cookie flags; secure bit only outside dev (no TLS on php -S).
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !$settings['debug'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('bsmembre');
    session_start();
}

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// Security headers on every response.
$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('X-Frame-Options', 'DENY')
        ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

// Error handling: log everything; show details only in dev.
$errorMiddleware = $app->addErrorMiddleware($settings['debug'], true, true);
$errorMiddleware->setDefaultErrorHandler(
    function (Request $request, Throwable $exception) use ($container, $settings, $app): Response {
        /** @var Logger $logger */
        $logger = $container->get(Logger::class);
        $logger->error('http', $exception->getMessage(), [
            'type' => $exception::class,
            'path' => $request->getUri()->getPath(),
            'file' => $exception->getFile() . ':' . $exception->getLine(),
        ]);

        $isNotFound = $exception instanceof \Slim\Exception\HttpNotFoundException
            || $exception instanceof \Slim\Exception\HttpMethodNotAllowedException;

        $response = $app->getResponseFactory()->createResponse($isNotFound ? 404 : 500);
        /** @var PhpRenderer $renderer */
        $renderer = $container->get(PhpRenderer::class);
        return $renderer->render($response, 'pages/error.php', [
            'title'    => $isNotFound ? 'Page introuvable' : 'Erreur',
            'notFound' => $isNotFound,
            'detail'   => $settings['debug'] ? (string) $exception : null,
        ]);
    }
);

(require __DIR__ . '/routes.php')($app);

return $app;
