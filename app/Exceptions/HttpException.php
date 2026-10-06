<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * HTTP-aware exception: carries a status code and envelope errors
 * (conventions §7: { field?, message } entries).
 */
class HttpException extends AppException
{
    /** @param array<int, array<string, mixed>|string> $errors */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        private readonly array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : 'HTTP error', $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<int, array<string, mixed>> */
    public function getErrors(): array
    {
        if ($this->errors === []) {
            return [['message' => $this->getMessage()]];
        }

        $normalized = [];
        foreach ($this->errors as $error) {
            if (!is_array($error)) {
                $error = ['message' => (string) $error];
            }
            if (!isset($error['message'])) {
                $error['message'] = $this->getMessage();
            }
            $normalized[] = $error;
        }
        return $normalized;
    }
}
