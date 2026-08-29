<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Tokens;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Jestays\Centrifugo\Identity\UserMapper;
use phpcent\Client;

final class TokenManager
{
    public function __construct(
        private readonly Client $client,
        private readonly UserMapper $users,
        private readonly int $defaultTtl,
    ) {
        $this->assertValidTtl($defaultTtl);
    }

    public function connectionToken(Authenticatable|string|int $user, ?int $ttl = null, array $info = []): string
    {
        return $this->client->generateConnectionToken(
            $this->mapUser($user),
            $this->expiresAt($ttl),
            $info,
        );
    }

    public function subscriptionToken(Authenticatable|string|int $user, string $centrifugoChannel, ?int $ttl = null, array $info = []): string
    {
        return $this->client->generateSubscriptionToken(
            $this->mapUser($user),
            $centrifugoChannel,
            $this->expiresAt($ttl),
            $info,
        );
    }

    private function mapUser(Authenticatable|string|int $user): string
    {
        return $this->users->map($user instanceof Authenticatable ? $user->getAuthIdentifier() : $user);
    }

    private function expiresAt(?int $ttl): int
    {
        $ttl ??= $this->defaultTtl;

        $this->assertValidTtl($ttl);

        return $ttl === 0 ? 0 : time() + $ttl;
    }

    private function assertValidTtl(int $ttl): void
    {
        if ($ttl < 0) {
            throw new InvalidArgumentException(
                "Centrifugo token TTL must be greater than or equal to 0 seconds, [{$ttl}] given. Use 0 for tokens without expiration."
            );
        }
    }
}
