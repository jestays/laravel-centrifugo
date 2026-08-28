<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Identity;

interface UserMapper
{
    public function map(string|int $id): string;
}
