<?php

use App\Http\Controllers\Api\V1\JournalController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware([
        'api.token',
        'throttle:60,1',
    ])
    ->group(function () {
        Route::post('journals', [JournalController::class, 'store'])
            ->middleware('require.token.perms:journal.create,journal.post');
    });
