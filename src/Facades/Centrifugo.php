<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Facades;

use Illuminate\Support\Facades\Facade;

final class Centrifugo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Jestays\Centrifugo\Centrifugo::class;
    }
}
