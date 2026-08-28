<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Channels;

interface ChannelMapper
{
    public function toCentrifugo(string $channel): string;

    public function toLaravel(string $channel): string;

    public function isPresence(string $channel): bool;
}
