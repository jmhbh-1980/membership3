<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Lazy PDO factory. MySQL 5.7 target — utf8mb4, InnoDB, strict errors,
 * native prepared statements only.
 */
final class Db
{
    private ?PDO $pdo = null;

    /** @param array{host?:string,port?:int|string,name?:string,user?:string,password?:string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->config['host'] ?? 'localhost',
                (int) ($this->config['port'] ?? 3306),
                $this->config['name'] ?? ''
            );
            $this->pdo = new PDO($dsn, $this->config['user'] ?? '', $this->config['password'] ?? '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return $this->pdo;
    }
}
