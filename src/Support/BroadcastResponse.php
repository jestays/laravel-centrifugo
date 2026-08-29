<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Support;

final class BroadcastResponse
{
    /**
     * @return array{index: int, error: mixed}|null
     */
    public static function firstError(array $response): ?array
    {
        $responses = $response['result']['responses'] ?? [];

        if (! is_array($responses)) {
            return null;
        }

        foreach ($responses as $index => $item) {
            if (is_array($item) && isset($item['error'])) {
                return ['index' => (int) $index, 'error' => $item['error']];
            }
        }

        return null;
    }
}
