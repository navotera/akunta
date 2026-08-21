<?php

use App\Http\Controllers\Api\InboundWebhookController;
use App\Http\Controllers\Api\Spa\AccountController;
use App\Http\Controllers\Api\Spa\AttachmentController;
use App\Http\Controllers\Api\Spa\AutoMappingController;
use App\Http\Controllers\Api\Spa\FakeDataController;
use App\Http\Controllers\Api\Spa\FiscalAdjustmentController;
use App\Http\Controllers\Api\Spa\OnboardingController;
use App\Http\Controllers\Api\Spa\PeriodController;
use App\Http\Controllers\Api\Spa\ReportingController;
use App\Http\Controllers\Api\Spa\SourceRefController;
use App\Http\Controllers\Api\Spa\Widgets\EcosystemController;
use App\Http\Controllers\Api\Spa\Widgets\FinancialPulseController;
use App\Http\Controllers\Api\Spa\Widgets\RecentJournalsController;
use App\Http\Controllers\Api\Spa\WorkspaceController;
use App\Http\Controllers\Api\V1\AccountBalanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutoMappingIngestController;
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
    Route::post('auth/local-login', [AuthController::class, 'localLogin']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
});

Route::middleware(['web', 'auth:sanctum'])
    ->prefix('v1')
    ->group(function () {
        Route::get('me', [AuthController::class, 'me']);
    });

Route::post('webhooks/incoming/{secret}', InboundWebhookController::class)
    ->middleware('throttle:120,1');

/*
|--------------------------------------------------------------------------
| SPA-only API surface (cookie auth) — `/api/v1/spa/*`
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'auth:sanctum'])
    ->prefix('v1/spa')
    ->group(function () {
        Route::get('accounts', [AccountController::class, 'index']);
        Route::get('accounts/{id}', [AccountController::class, 'show']);
        Route::post('accounts', [AccountController::class, 'store']);
        Route::patch('accounts/{id}', [AccountController::class, 'update']);
        Route::delete('accounts/{id}', [AccountController::class, 'destroy']);

        Route::get('periods', [PeriodController::class, 'index']);
        Route::get('periods/{id}', [PeriodController::class, 'show']);
        Route::post('periods', [PeriodController::class, 'store']);
        Route::patch('periods/{id}', [PeriodController::class, 'update']);
        Route::delete('periods/{id}', [PeriodController::class, 'destroy']);
        Route::post('periods/{id}/close', [PeriodController::class, 'close']);
        Route::post('periods/{id}/reopen', [PeriodController::class, 'reopen']);
        Route::get('journal-templates', [App\Http\Controllers\Api\Spa\JournalTemplateController::class, 'index']);
        Route::get('journal-templates/{id}', [App\Http\Controllers\Api\Spa\JournalTemplateController::class, 'show']);
        Route::post('journal-templates', [App\Http\Controllers\Api\Spa\JournalTemplateController::class, 'store']);
        Route::patch('journal-templates/{id}', [App\Http\Controllers\Api\Spa\JournalTemplateController::class, 'update']);
        Route::patch('journal-templates/{id}/bookmark', [App\Http\Controllers\Api\Spa\JournalTemplateController::class, 'bookmark']);
        Route::delete('journal-templates/{id}', [App\Http\Controllers\Api\Spa\JournalTemplateController::class, 'destroy']);

        Route::get('recurring-journals', [App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'index']);
        Route::get('recurring-journals/{id}', [App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'show']);
        Route::post('recurring-journals', [App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'store']);
        Route::patch('recurring-journals/{id}', [App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'update']);
        Route::delete('recurring-journals/{id}', [App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'destroy']);
        Route::post('recurring-journals/{id}/pause', [App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'pause']);
        Route::post('recurring-journals/{id}/resume', [App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'resume']);
        Route::post('recurring-journals/{id}/run', [App\Http\Controllers\Api\Spa\RecurringJournalController::class, 'run']);

        Route::get('attachments', [AttachmentController::class, 'index']);
        Route::get('attachments/{id}', [AttachmentController::class, 'show']);
        Route::post('attachments', [AttachmentController::class, 'store']);
        Route::delete('attachments/{id}', [AttachmentController::class, 'destroy']);

        Route::get('journals', [App\Http\Controllers\Api\Spa\JournalController::class, 'index']);
        Route::get('journals/next-number', [App\Http\Controllers\Api\Spa\JournalController::class, 'nextNumber']);
        Route::get('journals/next-transaction-code', [App\Http\Controllers\Api\Spa\JournalController::class, 'nextTransactionCode']);
        Route::get('journals/{id}', [App\Http\Controllers\Api\Spa\JournalController::class, 'show']);
        Route::post('journals', [App\Http\Controllers\Api\Spa\JournalController::class, 'store']);
        Route::patch('journals/{id}', [App\Http\Controllers\Api\Spa\JournalController::class, 'update']);
        Route::delete('journals/{id}', [App\Http\Controllers\Api\Spa\JournalController::class, 'destroy']);
        Route::post('journals/{id}/post', [App\Http\Controllers\Api\Spa\JournalController::class, 'post']);
        Route::post('journals/{id}/submit', [App\Http\Controllers\Api\Spa\JournalController::class, 'submit']);
        Route::post('journals/{id}/reject', [App\Http\Controllers\Api\Spa\JournalController::class, 'reject']);
        Route::post('journals/{id}/reverse', [App\Http\Controllers\Api\Spa\JournalController::class, 'reverse']);
        Route::post('journals/{id}/replicate', [App\Http\Controllers\Api\Spa\JournalController::class, 'replicate']);

        Route::get('fiscal-adjustments', [FiscalAdjustmentController::class, 'index']);
        Route::get('fiscal-adjustments/{id}', [FiscalAdjustmentController::class, 'show']);
        Route::post('fiscal-adjustments', [FiscalAdjustmentController::class, 'store']);
        Route::patch('fiscal-adjustments/{id}', [FiscalAdjustmentController::class, 'update']);
        Route::post('fiscal-adjustments/{id}/approve', [FiscalAdjustmentController::class, 'approve']);
        Route::delete('fiscal-adjustments/{id}', [FiscalAdjustmentController::class, 'destroy']);

        Route::get('tax-provisions/current', [App\Http\Controllers\Api\Spa\TaxProvisionController::class, 'show']);
        Route::post('tax-provisions/preview', [App\Http\Controllers\Api\Spa\TaxProvisionController::class, 'preview']);
        Route::post('tax-provisions', [App\Http\Controllers\Api\Spa\TaxProvisionController::class, 'store']);

        Route::get('fake-data', [FakeDataController::class, 'index']);
        Route::post('fake-data/import-all', [FakeDataController::class, 'importAll']);
        Route::post('fake-data/{group}/import', [FakeDataController::class, 'import']);
        Route::delete('fake-data/{group}', [FakeDataController::class, 'destroy']);
        Route::post('fake-data/impersonate/{userId}', [FakeDataController::class, 'impersonate']);
        Route::post('fake-data/stop-impersonation', [FakeDataController::class, 'stopImpersonation']);

        Route::get('auto-mapping', [AutoMappingController::class, 'index']);
        Route::get('auto-mapping/{id}', [AutoMappingController::class, 'show']);
        Route::post('auto-mapping/{id}/mapping', [AutoMappingController::class, 'saveRule']);
        Route::post('auto-mapping/rules/{ruleId}/reprocess', [AutoMappingController::class, 'reprocess']);

        Route::get('reports/trial-balance', [ReportingController::class, 'trialBalance']);
        Route::get('reports/balance-sheet', [ReportingController::class, 'balanceSheet']);
        Route::get('reports/income-statement', [ReportingController::class, 'incomeStatement']);
        Route::get('reports/general-ledger', [ReportingController::class, 'generalLedger']);
        Route::get('reports/fiscal-reconciliation', [ReportingController::class, 'fiscalReconciliation']);
        Route::get('reports/by-source-ref', [SourceRefController::class, 'bySourceRef']);

        Route::get('source-refs', [SourceRefController::class, 'index']);

        Route::get('onboarding/status', [OnboardingController::class, 'status']);
        Route::get('onboarding/coa-templates', [OnboardingController::class, 'coaTemplates']);
        Route::post('onboarding/bookkeeping-mode', [OnboardingController::class, 'bookkeepingMode']);
        Route::post('onboarding/apply-coa', [OnboardingController::class, 'applyCoa']);

        Route::get('widgets/financial-pulse', [FinancialPulseController::class, 'show']);
        Route::get('widgets/recent-journals', [RecentJournalsController::class, 'index']);
        Route::get('widgets/ecosystem', [EcosystemController::class, 'index']);

        Route::get('workspaces', [WorkspaceController::class, 'index']);
        Route::post('workspaces', [WorkspaceController::class, 'store']);
        Route::patch('workspaces/{id}', [WorkspaceController::class, 'update']);
        Route::post('workspaces/{id}/logo', [WorkspaceController::class, 'logo']);

        Route::get('webhooks', [App\Http\Controllers\Api\Spa\WebhookSubscriptionController::class, 'index']);
        Route::get('webhooks/logs', [App\Http\Controllers\Api\Spa\WebhookSubscriptionController::class, 'logs']);
        Route::get('webhooks/{id}/logs', [App\Http\Controllers\Api\Spa\WebhookSubscriptionController::class, 'subscriptionLogs']);
        Route::post('webhooks', [App\Http\Controllers\Api\Spa\WebhookSubscriptionController::class, 'store']);
        Route::patch('webhooks/{id}', [App\Http\Controllers\Api\Spa\WebhookSubscriptionController::class, 'update']);
        Route::delete('webhooks/{id}', [App\Http\Controllers\Api\Spa\WebhookSubscriptionController::class, 'destroy']);
        Route::post('webhooks/{id}/regenerate-url', [App\Http\Controllers\Api\Spa\WebhookSubscriptionController::class, 'regenerateUrl']);
    });

Route::prefix('v1')
    ->middleware([
        'api.token',
        'throttle:60,1',
    ])
    ->group(function () {
        Route::post('auto-mapping/ingest', [AutoMappingIngestController::class, 'store'])
            ->middleware('require.token.perms:journal.create,journal.post');
        Route::post('journals', [JournalController::class, 'store'])
            ->middleware('require.token.perms:journal.create,journal.post');

        // Bulk journal posting — Multi-Status (207) per-item result
        Route::post('journals/bulk', [JournalController::class, 'bulk'])
            ->middleware('require.token.perms:journal.create,journal.post');

        // Account balance lookup (sibling apps need credit-limit / cash-availability checks)
        Route::get('accounts/{account}/balance', [AccountBalanceController::class, 'show'])
            ->middleware('require.token.perms:journal.create');

        // Webhook subscriptions
        Route::get('webhooks', [WebhookSubscriptionController::class, 'index'])
            ->middleware('require.token.perms:journal.create');
        Route::get('webhooks/{id}', [WebhookSubscriptionController::class, 'show'])
            ->middleware('require.token.perms:journal.create');
        Route::post('webhooks', [WebhookSubscriptionController::class, 'store'])
            ->middleware('require.token.perms:journal.create');
        Route::patch('webhooks/{id}', [WebhookSubscriptionController::class, 'update'])
            ->middleware('require.token.perms:journal.create');
        Route::delete('webhooks/{id}', [WebhookSubscriptionController::class, 'destroy'])
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

// Compatibility alias for integrations using the shorter documented path.
Route::post('auto-mapping/ingest', [AutoMappingIngestController::class, 'store'])
    ->middleware(['api.token', 'throttle:60,1', 'require.token.perms:journal.create,journal.post']);
