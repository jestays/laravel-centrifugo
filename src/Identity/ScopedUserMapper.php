<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Identity;

final class ScopedUserMapper implements UserMapper
{
    public function __construct(private readonly string $application) {}

    public function map(string|int $id): string
    {
        return "{$this->application}:{$id}";
    }
}
