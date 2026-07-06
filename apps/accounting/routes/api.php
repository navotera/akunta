<?php

use App\Http\Controllers\Api\V1\AccountBalanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\JournalTemplateController;
use App\Http\Controllers\Api\V1\RecurringJournalController;
use App\Http\Controllers\Api\V1\WebhookSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SPA cookie auth (Sanctum stateful) — apps/accounting-web
|--------------------------------------------------------------------------
| `web` middleware loads cookie session; statefulApi() in bootstrap/app.php
| promotes first-party calls from configured Sanctum domains so that
| `auth:sanctum` resolves the session user.
*/
Route::middleware('web')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
});

Route::middleware(['web', 'auth:sanctum'])
    ->prefix('v1')
    ->group(function () {
        Route::get('me', [AuthController::class, 'me']);
    });

/*
|--------------------------------------------------------------------------
| SPA-only API surface (cookie auth) — `/api/v1/spa/*`
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'auth:sanctum'])
    ->prefix('v1/spa')
    ->group(function () {
        Route::get('accounts', [\App\Http\Controllers\Api\Spa\AccountController::class, 'index']);
        Route::get('accounts/{id}', [\App\Http\Controllers\Api\Spa\AccountController::class, 'show']);
        Route::post('accounts', [\App\Http\Controllers\Api\Spa\AccountController::class, 'store']);
        Route::patch('accounts/{id}', [\App\Http\Controllers\Api\Spa\AccountController::class, 'update']);
        Route::delete('accounts/{id}', [\App\Http\Controllers\Api\Spa\AccountController::class, 'destroy']);

        Route::get('periods', [\App\Http\Controllers\Api\Spa\PeriodController::class, 'index']);
        Route::get('periods/{id}', [\App\Http\Controllers\Api\Spa\PeriodController::class, 'show']);
        Route::post('periods', [\App\Http\Controllers\Api\Spa\PeriodController::class, 'store']);
        Route::patch('periods/{id}', [\App\Http\Controllers\Api\Spa\PeriodController::class, 'update']);
        Route::delete('periods/{id}', [\App\Http\Controllers\Api\Spa\PeriodController::class, 'destroy']);
        Route::post('periods/{id}/close', [\App\Http\Controllers\Api\Spa\PeriodController::class, 'close']);
        Route::post('periods/{id}/reopen', [\App\Http\Controllers\Api\Spa\PeriodController::class, 'reopen']);
        Route::get('journal-templates', [\App\Http\Controllers\Api\Spa\JournalTemplateController::class, 'index']);
        Route::get('journal-templates/{id}', [\App\Http\Controllers\Api\Spa\JournalTemplateController::class, 'show']);
        Route::post('journal-templates', [\App\Http\Controllers\Api\Spa\JournalTemplateController::class, 'store']);
        Route::patch('journal-templates/{id}', [\App\Http\Controllers\Api\Spa\JournalTemplateController::class, 'update']);
        Route::delete('journal-templates/{id}', [\App\Http\Controllers\Api\Spa\JournalTemplateController::class, 'destroy']);

        Route::get('recurring-journals', [\App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'index']);
        Route::get('recurring-journals/{id}', [\App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'show']);
        Route::post('recurring-journals', [\App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'store']);
        Route::patch('recurring-journals/{id}', [\App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'update']);
        Route::delete('recurring-journals/{id}', [\App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'destroy']);
        Route::post('recurring-journals/{id}/pause', [\App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'pause']);
        Route::post('recurring-journals/{id}/resume', [\App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'resume']);
        Route::post('recurring-journals/{id}/run', [\App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'run']);

        Route::get('attachments', [\App\Http\Controllers\Api\Spa\AttachmentController::class, 'index']);
        Route::get('attachments/{id}', [\App\Http\Controllers\Api\Spa\AttachmentController::class, 'show']);
        Route::post('attachments', [\App\Http\Controllers\Api\Spa\AttachmentController::class, 'store']);
        Route::delete('attachments/{id}', [\App\Http\Controllers\Api\Spa\AttachmentController::class, 'destroy']);

        Route::get('journals', [\App\Http\Controllers\Api\Spa\JournalController::class, 'index']);
        Route::get('journals/{id}', [\App\Http\Controllers\Api\Spa\JournalController::class, 'show']);
        Route::post('journals', [\App\Http\Controllers\Api\Spa\JournalController::class, 'store']);
        Route::patch('journals/{id}', [\App\Http\Controllers\Api\Spa\JournalController::class, 'update']);
        Route::delete('journals/{id}', [\App\Http\Controllers\Api\Spa\JournalController::class, 'destroy']);
        Route::post('journals/{id}/post', [\App\Http\Controllers\Api\Spa\JournalController::class, 'post']);
        Route::post('journals/{id}/reverse', [\App\Http\Controllers\Api\Spa\JournalController::class, 'reverse']);
        Route::post('journals/{id}/replicate', [\App\Http\Controllers\Api\Spa\JournalController::class, 'replicate']);

        Route::get('reports/trial-balance', [\App\Http\Controllers\Api\Spa\ReportingController::class, 'trialBalance']);
        Route::get('reports/balance-sheet', [\App\Http\Controllers\Api\Spa\ReportingController::class, 'balanceSheet']);
        Route::get('reports/income-statement', [\App\Http\Controllers\Api\Spa\ReportingController::class, 'incomeStatement']);
        Route::get('reports/general-ledger', [\App\Http\Controllers\Api\Spa\ReportingController::class, 'generalLedger']);
        Route::get('reports/by-source-ref', [\App\Http\Controllers\Api\Spa\SourceRefController::class, 'bySourceRef']);

        Route::get('source-refs', [\App\Http\Controllers\Api\Spa\SourceRefController::class, 'index']);

        Route::get('onboarding/status', [\App\Http\Controllers\Api\Spa\OnboardingController::class, 'status']);
        Route::get('onboarding/coa-templates', [\App\Http\Controllers\Api\Spa\OnboardingController::class, 'coaTemplates']);
        Route::post('onboarding/apply-coa', [\App\Http\Controllers\Api\Spa\OnboardingController::class, 'applyCoa']);

        Route::get('widgets/financial-pulse', [\App\Http\Controllers\Api\Spa\Widgets\FinancialPulseController::class, 'show']);
        Route::get('widgets/recent-journals', [\App\Http\Controllers\Api\Spa\Widgets\RecentJournalsController::class, 'index']);
        Route::get('widgets/ecosystem', [\App\Http\Controllers\Api\Spa\Widgets\EcosystemController::class, 'index']);

        Route::get('webhooks', [\App\Http\Controllers\Api\Spa\WebhookSubscriptionController::class, 'index']);
        Route::post('webhooks', [\App\Http\Controllers\Api\Spa\WebhookSubscriptionController::class, 'store']);
        Route::patch('webhooks/{id}', [\App\Http\Controllers\Api\Spa\WebhookSubscriptionController::class, 'update']);
        Route::delete('webhooks/{id}', [\App\Http\Controllers\Api\Spa\WebhookSubscriptionController::class, 'destroy']);
        Route::post('webhooks/{id}/rotate-secret', [\App\Http\Controllers\Api\Spa\WebhookSubscriptionController::class, 'rotateSecret']);
    });

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
