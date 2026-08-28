<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jestays\Centrifugo\Tokens\TokenManager;

final class ConnectionTokenController
{
    public function __construct(private readonly TokenManager $tokens) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_if(! $request->user(), 401);

        return response()->json([
            'token' => $this->tokens->connectionToken($request->user()),
        ]);
    }
}
