<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Http\Controllers;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jestays\Centrifugo\Broadcasting\CentrifugoBroadcaster;
use RuntimeException;

final class SubscriptionTokenController
{
    public function __construct(private readonly BroadcastManager $broadcastManager) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'channel' => ['required', 'string'],
        ]);

        $broadcaster = $this->broadcastManager->connection();

        if (! $broadcaster instanceof CentrifugoBroadcaster) {
            throw new RuntimeException(
                'The default broadcasting connection is not centrifugo. Broadcast::channel() callbacks are '.
                'registered on the default connection, so set BROADCAST_CONNECTION=centrifugo for subscription '.
                'authorization to work.',
            );
        }

        return response()->json($broadcaster->auth($request));
    }
}
