<?php

namespace Lkn\BBPix\App\Pix\Exceptions;

use RuntimeException;

final class Journey4PublicException extends RuntimeException
{
    private int $statusCode;

    private string $step;

    public function __construct(string $message, int $statusCode = 500, string $step = '')
    {
        parent::__construct($message);

        $this->statusCode = $statusCode;
        $this->step = $step;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getStep(): string
    {
        return $this->step;
    }
}