<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Requested resource does not exist → HTTP 404. */
class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not Found', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, [['message' => $message]], $previous);
    }
}
