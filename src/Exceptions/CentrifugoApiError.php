<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Exceptions;

use RuntimeException;

final class CentrifugoApiError extends RuntimeException
{
    public static function fromResponse(array $response): self
    {
        return self::fromError($response['error'] ?? null);
    }

    public static function forBroadcastChannel(mixed $error, ?string $channel): self
    {
        $base = self::fromError($error);

        if ($channel === null) {
            return $base;
        }

        return new self("{$base->getMessage()} (channel: {$channel})", $base->getCode());
    }

    private static function fromError(mixed $error): self
    {
        if (is_array($error)) {
            return new self(
                (string) ($error['message'] ?? 'Unknown Centrifugo API error.'),
                (int) ($error['code'] ?? 0),
            );
        }

        return new self((string) ($error ?? 'Unknown Centrifugo API error.'));
    }
}
