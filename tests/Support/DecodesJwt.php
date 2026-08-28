<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Tests\Support;

trait DecodesJwt
{
    protected function decodeJwtPayload(string $token): array
    {
        [, $payload] = explode('.', $token);

        return json_decode($this->decodeBase64Url($payload), true);
    }

    protected function decodeBase64Url(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;

        return base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/')) ?: '';
    }
}
