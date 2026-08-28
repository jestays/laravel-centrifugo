<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Jestays\Centrifugo\Channels\ChannelMapper;
use Jestays\Centrifugo\Exceptions\InvalidCentrifugoChannel;
use Jestays\Centrifugo\Tokens\TokenManager;
use phpcent\Client;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class CentrifugoBroadcaster extends Broadcaster
{
    public function __construct(
        private readonly Client $client,
        private readonly ChannelMapper $mapper,
        private readonly TokenManager $tokens,
    ) {}

    public function auth($request)
    {
        if (! $request->user()) {
            throw new HttpException(401);
        }

        $centrifugoChannel = $request->input('channel');

        if (! is_string($centrifugoChannel) || $centrifugoChannel === '') {
            throw new HttpException(422, 'The channel field is required.');
        }

        try {
            $laravelChannel = $this->mapper->toLaravel($centrifugoChannel);
        } catch (InvalidCentrifugoChannel $exception) {
            throw new HttpException(403, $exception->getMessage(), $exception);
        }

        $result = $this->verifyUserCanAccessChannel($request, $laravelChannel);

        $info = $this->mapper->isPresence($centrifugoChannel) && is_array($result) ? $result : [];

        return [
            'token' => $this->tokens->subscriptionToken($request->user(), $centrifugoChannel, null, $info),
        ];
    }

    public function validAuthenticationResponse($request, $result)
    {
        return $result;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $payload['event'] = $event;

        $mapped = array_values(array_map(
            fn (string $channel): string => $this->mapper->toCentrifugo($channel),
            $this->formatChannels($channels),
        ));

        try {
            $response = $this->client->broadcast($mapped, $payload);
        } catch (Throwable $exception) {
            throw new BroadcastException($exception->getMessage(), 0, $exception);
        }

        $this->assertBroadcastSucceeded($response);
    }

    private function assertBroadcastSucceeded(mixed $response): void
    {
        if (! is_array($response)) {
            return;
        }

        if (isset($response['error'])) {
            throw new BroadcastException($this->describeError($response['error']));
        }

        foreach ($response['result']['responses'] ?? [] as $item) {
            if (is_array($item) && isset($item['error'])) {
                throw new BroadcastException($this->describeError($item['error']));
            }
        }
    }

    private function describeError(mixed $error): string
    {
        if (is_array($error)) {
            return (string) ($error['message'] ?? 'Unknown Centrifugo broadcast error.');
        }

        return (string) $error;
    }
}
