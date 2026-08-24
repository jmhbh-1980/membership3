<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single application-wide logger. Every log write in the app goes through
 * this class, to the file configured in settings (app_logs/membership.log,
 * outside the webroot — never Ionos's own logs/ directory).
 */
final class Logger
{
    public function __construct(private readonly string $logFile)
    {
    }

    public function info(string $context, string $message, array $data = []): void
    {
        $this->write('INFO', $context, $message, $data);
    }

    public function error(string $context, string $message, array $data = []): void
    {
        $this->write('ERROR', $context, $message, $data);
    }

    /**
     * @param string $context Originating file/feature, e.g. "auth", "payment".
     */
    private function write(string $level, string $context, string $message, array $data): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] [%s] [%s] %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $context,
            $message,
            $data !== [] ? ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
