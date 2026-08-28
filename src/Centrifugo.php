<?php

declare(strict_types=1);

namespace Jestays\Centrifugo;

use Illuminate\Contracts\Auth\Authenticatable;
use Jestays\Centrifugo\Channels\ChannelMapper;
use Jestays\Centrifugo\Exceptions\CentrifugoApiError;
use Jestays\Centrifugo\Identity\UserMapper;
use Jestays\Centrifugo\Tokens\TokenManager;
use phpcent\Client;

final class Centrifugo
{
    public function __construct(
        private readonly Client $client,
        private readonly ChannelMapper $channels,
        private readonly UserMapper $users,
        private readonly TokenManager $tokens,
    ) {}

    public function publish(string $channel, array $data, bool $skipHistory = false): array
    {
        return $this->assertSuccessful($this->client->publish($this->channels->toCentrifugo($channel), $data, $skipHistory));
    }

    public function broadcast(array $channels, array $data, bool $skipHistory = false): array
    {
        $mapped = array_map(fn (string $channel): string => $this->channels->toCentrifugo($channel), $channels);

        return $this->assertSuccessful($this->client->broadcast($mapped, $data, $skipHistory));
    }

    public function presence(string $channel): array
    {
        return $this->assertSuccessful($this->client->presence($this->channels->toCentrifugo($channel)));
    }

    public function presenceStats(string $channel): array
    {
        return $this->assertSuccessful($this->client->presenceStats($this->channels->toCentrifugo($channel)));
    }

    public function history(string $channel, int $limit = 0, array $since = [], bool $reverse = false): array
    {
        return $this->assertSuccessful($this->client->history($this->channels->toCentrifugo($channel), $limit, $since, $reverse));
    }

    public function historyRemove(string $channel): array
    {
        return $this->assertSuccessful($this->client->historyRemove($this->channels->toCentrifugo($channel)));
    }

    public function subscribe(string $channel, string|int $user, string $client = ''): array
    {
        return $this->assertSuccessful($this->client->subscribe(
            $this->channels->toCentrifugo($channel),
            $this->users->map($user),
            $client,
        ));
    }

    public function unsubscribe(string $channel, string|int $user, string $client = ''): array
    {
        return $this->assertSuccessful($this->client->unsubscribe(
            $this->channels->toCentrifugo($channel),
            $this->users->map($user),
            $client,
        ));
    }

    public function disconnect(string|int $user, string $client = ''): array
    {
        return $this->assertSuccessful($this->client->disconnect($this->users->map($user), $client));
    }

    public function channels(string $pattern = ''): array
    {
        return $this->assertSuccessful($this->client->channels($pattern));
    }

    public function info(): array
    {
        return $this->assertSuccessful($this->client->info());
    }

    public function connectionToken(Authenticatable|string|int $user, ?int $ttl = null, array $info = []): string
    {
        return $this->tokens->connectionToken($user, $ttl, $info);
    }

    public function subscriptionToken(Authenticatable|string|int $user, string $channel, ?int $ttl = null, array $info = []): string
    {
        return $this->tokens->subscriptionToken($user, $this->channels->toCentrifugo($channel), $ttl, $info);
    }

    public function client(): Client
    {
        return $this->client;
    }

    private function assertSuccessful(array $response): array
    {
        if (isset($response['error'])) {
            throw CentrifugoApiError::fromResponse($response);
        }

        return $response;
    }
}
