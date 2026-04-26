<?php

use App\Http\Controllers\Api\V1\AccountBalanceController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\JournalTemplateController;
use App\Http\Controllers\Api\V1\RecurringJournalController;
use App\Http\Controllers\Api\V1\WebhookSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware([
        'api.token',
        'throttle:60,1',
    ])
    ->group(function () {
        Route::post('journals', [JournalController::class, 'store'])
            ->middleware('require.token.perms:journal.create,journal.post');

        // Bulk journal posting — Multi-Status (207) per-item result
        Route::post('journals/bulk', [JournalController::class, 'bulk'])
            ->middleware('require.token.perms:journal.create,journal.post');

        // Account balance lookup (sibling apps need credit-limit / cash-availability checks)
        Route::get('accounts/{account}/balance', [AccountBalanceController::class, 'show'])
            ->middleware('require.token.perms:journal.create');

        // Webhook subscriptions
        Route::get('webhooks',           [WebhookSubscriptionController::class, 'index'])
            ->middleware('require.token.perms:journal.create');
        Route::get('webhooks/{id}',      [WebhookSubscriptionController::class, 'show'])
            ->middleware('require.token.perms:journal.create');
        Route::post('webhooks',          [WebhookSubscriptionController::class, 'store'])
            ->middleware('require.token.perms:journal.create');
        Route::patch('webhooks/{id}',    [WebhookSubscriptionController::class, 'update'])
            ->middleware('require.token.perms:journal.create');
        Route::delete('webhooks/{id}',   [WebhookSubscriptionController::class, 'destroy'])
            ->middleware('require.token.perms:journal.create');
        Route::post('webhooks/{id}/rotate-secret', [WebhookSubscriptionController::class, 'rotateSecret'])
            ->middleware('require.token.perms:journal.create');

        // Journal templates
        Route::get('journal-templates', [JournalTemplateController::class, 'index'])
            ->middleware('require.token.perms:journal.create');
        Route::get('journal-templates/{id}', [JournalTemplateController::class, 'show'])
            ->middleware('require.token.perms:journal.create');
        Route::post('journal-templates', [JournalTemplateController::class, 'store'])
            ->middleware('require.token.perms:journal.create');
        Route::delete('journal-templates/{id}', [JournalTemplateController::class, 'destroy'])
            ->middleware('require.token.perms:journal.create');
        Route::post('journal-templates/{id}/instantiate', [JournalTemplateController::class, 'instantiate'])
            ->middleware('require.token.perms:journal.create');

        // Recurring journals
        Route::get('recurring-journals', [RecurringJournalController::class, 'index'])
            ->middleware('require.token.perms:journal.create');
        Route::get('recurring-journals/{id}', [RecurringJournalController::class, 'show'])
            ->middleware('require.token.perms:journal.create');
        Route::post('recurring-journals', [RecurringJournalController::class, 'store'])
            ->middleware('require.token.perms:journal.create');
        Route::patch('recurring-journals/{id}', [RecurringJournalController::class, 'update'])
            ->middleware('require.token.perms:journal.create');
        Route::delete('recurring-journals/{id}', [RecurringJournalController::class, 'destroy'])
            ->middleware('require.token.perms:journal.create');
        Route::post('recurring-journals/{id}/pause', [RecurringJournalController::class, 'pause'])
            ->middleware('require.token.perms:journal.create');
        Route::post('recurring-journals/{id}/resume', [RecurringJournalController::class, 'resume'])
            ->middleware('require.token.perms:journal.create');
        Route::post('recurring-journals/{id}/run', [RecurringJournalController::class, 'run'])
            ->middleware('require.token.perms:journal.create,journal.post');
    });
