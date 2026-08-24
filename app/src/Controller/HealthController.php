<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\Db;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Healthcheck: verifies template rendering happened upstream (route works),
 * log file writability and — when credentials are configured — DB access.
 */
final class HealthController
{
    public function __construct(
        private readonly Db $db,
        private readonly Logger $logger,
        private readonly array $settings,
    ) {
    }

    public function check(Request $request, Response $response): Response
    {
        $checks = [
            'app' => 'ok',
            'php' => PHP_VERSION,
        ];

        $logDir = dirname($this->settings['paths']['log_file']);
        $checks['log'] = (is_dir($logDir) || @mkdir($logDir, 0775, true)) && is_writable($logDir) ? 'ok' : 'ko';

        if (($this->settings['db']['host'] ?? '') !== '') {
            try {
                $this->db->pdo()->query('SELECT 1');
                $checks['db'] = 'ok';
            } catch (\Throwable $e) {
                $checks['db'] = 'ko';
                $this->logger->error('health', 'DB check failed', ['error' => $e->getMessage()]);
            }
        } else {
            $checks['db'] = 'non configurée';
        }

        $ok = !in_array('ko', $checks, true);
        $response->getBody()->write(json_encode(
            ['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks],
            JSON_UNESCAPED_UNICODE
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($ok ? 200 : 503);
    }
}
