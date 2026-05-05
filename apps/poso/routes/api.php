<?php

use App\Http\Controllers\Api\V1\AccountingReferenceController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\PurchaseBillController;
use App\Http\Controllers\Api\V1\SalesInvoiceController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SPA cookie auth (Sanctum stateful) — apps/poso-web
|--------------------------------------------------------------------------
| `web` middleware loads cookie session; statefulApi() in bootstrap/app.php
| promotes first-party calls from configured Sanctum domains so that
| `auth:sanctum` resolves the session user.
*/
Route::middleware(['web', 'auth:sanctum'])
    ->prefix('v1')
    ->group(function () {
        Route::get('me', [BootstrapController::class, 'me']);
        Route::post('context/entity', [BootstrapController::class, 'selectEntity']);

        Route::get('accounting/journal-templates', [AccountingReferenceController::class, 'journalTemplates']);
        Route::get('accounting/accounts', [AccountingReferenceController::class, 'accounts']);

        Route::get('dashboard', [WorkspaceController::class, 'dashboard']);
        Route::get('customers', [WorkspaceController::class, 'customers']);
        Route::post('customers', [WorkspaceController::class, 'storeCustomer']);
        Route::get('suppliers', [WorkspaceController::class, 'suppliers']);
        Route::post('suppliers', [WorkspaceController::class, 'storeSupplier']);
        Route::get('products', [WorkspaceController::class, 'products']);
        Route::post('products', [WorkspaceController::class, 'storeProduct']);
        Route::get('price-lists', [WorkspaceController::class, 'priceLists']);
        Route::get('inventory', [WorkspaceController::class, 'inventory']);
        Route::get('payments', [WorkspaceController::class, 'payments']);
        Route::get('reports/summary', [WorkspaceController::class, 'reports']);
        Route::get('integrations/akunta/events', [WorkspaceController::class, 'integrationEvents']);
        Route::get('admin/users', [WorkspaceController::class, 'users']);
        Route::get('admin/roles', [WorkspaceController::class, 'roles']);
        Route::get('admin/audit-log', [WorkspaceController::class, 'auditLog']);
        Route::get('settings', [WorkspaceController::class, 'settings']);
        Route::post('settings/journal-template-mappings', [WorkspaceController::class, 'saveJournalTemplateMapping']);

        Route::get('sales/invoices', [SalesInvoiceController::class, 'index']);
        Route::post('sales/invoices', [SalesInvoiceController::class, 'store']);

        Route::get('purchases/bills', [PurchaseBillController::class, 'index']);
        Route::post('purchases/bills', [PurchaseBillController::class, 'store']);
    });
