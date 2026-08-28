<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Channels;

use Jestays\Centrifugo\Exceptions\InvalidCentrifugoChannel;

final class ScopedChannelMapper implements ChannelMapper
{
    private const SEGMENT_PATTERN = '/\A[A-Za-z0-9@,;._=\-]+\z/';

    public function __construct(
        private readonly string $application,
        private readonly array $namespaces,
    ) {}

    public function toCentrifugo(string $channel): string
    {
        if (str_starts_with($channel, 'private-')) {
            $rest = substr($channel, strlen('private-'));

            return $this->format($this->namespaces['private'], $this->validateSegment($rest, $channel));
        }

        if (str_starts_with($channel, 'presence-')) {
            $rest = substr($channel, strlen('presence-'));

            return $this->format($this->namespaces['presence'], $this->validateSegment($rest, $channel));
        }

        return $this->format($this->namespaces['public'], $this->validateSegment($channel, $channel));
    }

    public function toLaravel(string $channel): string
    {
        return $this->parse($channel)[1];
    }

    public function isPresence(string $channel): bool
    {
        return $this->parse($channel)[0] === $this->namespaces['presence'];
    }

    private function format(string $namespace, string $channel): string
    {
        return "{$namespace}:{$this->application}.{$channel}";
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parse(string $channel): array
    {
        if (! str_contains($channel, ':')) {
            throw new InvalidCentrifugoChannel("Centrifugo channel [{$channel}] is missing a namespace.");
        }

        [$namespace, $remainder] = explode(':', $channel, 2);

        if (! in_array($namespace, $this->namespaces, true)) {
            throw new InvalidCentrifugoChannel("Centrifugo channel [{$channel}] uses an unknown namespace [{$namespace}].");
        }

        $prefix = "{$this->application}.";

        if (! str_starts_with($remainder, $prefix)) {
            throw new InvalidCentrifugoChannel("Centrifugo channel [{$channel}] does not belong to application [{$this->application}].");
        }

        $rest = substr($remainder, strlen($prefix));

        return [$namespace, $this->validateSegment($rest, $channel)];
    }

    private function validateSegment(string $segment, string $original): string
    {
        if ($segment === '' || preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
            throw new InvalidCentrifugoChannel("Centrifugo channel [{$original}] has an invalid channel name [{$segment}].");
        }

        return $segment;
    }
}
