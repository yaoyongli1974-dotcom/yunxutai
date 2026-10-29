<?php
declare(strict_types=1);

namespace App\Core;

class HttpException extends \RuntimeException
{
    public function __construct(private readonly int $statusCode, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : 'HTTP ' . $statusCode, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
