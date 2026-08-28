<?php

use Illuminate\Support\Facades\Route;
use Jestays\Centrifugo\Http\Controllers\ConnectionTokenController;
use Jestays\Centrifugo\Http\Controllers\SubscriptionTokenController;

Route::post('connection-token', ConnectionTokenController::class)->name('centrifugo.connection-token');
Route::post('subscription-token', SubscriptionTokenController::class)->name('centrifugo.subscription-token');
