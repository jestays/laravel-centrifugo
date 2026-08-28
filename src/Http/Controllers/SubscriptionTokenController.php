<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Http\Controllers;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SubscriptionTokenController
{
    public function __construct(private readonly BroadcastManager $broadcastManager) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'channel' => ['required', 'string'],
        ]);

        return response()->json(
            $this->broadcastManager->connection('centrifugo')->auth($request),
        );
    }
}
