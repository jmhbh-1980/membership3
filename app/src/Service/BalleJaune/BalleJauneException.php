<?php

declare(strict_types=1);

namespace App\Service\BalleJaune;

use RuntimeException;

final class BalleJauneException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?int $apiCode = null,
    ) {
        parent::__construct($message);
    }
}
