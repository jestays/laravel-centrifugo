<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Exceptions;

use RuntimeException;

final class CentrifugoApiError extends RuntimeException
{
    public static function fromResponse(array $response): self
    {
        $error = $response['error'] ?? null;

        if (is_array($error)) {
            return new self(
                (string) ($error['message'] ?? 'Unknown Centrifugo API error.'),
                (int) ($error['code'] ?? 0),
            );
        }

        return new self((string) ($error ?? 'Unknown Centrifugo API error.'));
    }
}
