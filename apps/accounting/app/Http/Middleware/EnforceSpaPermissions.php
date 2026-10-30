<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Models\Account;
use App\Models\RecurringJournal;
use App\Services\InstallationOnboardingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EnforceSpaPermissions
{
    use ResolvesTenant;

    private const ACTIONS = [
        'AccountController' => ['index' => 'account.read', 'show' => 'account.read', 'store' => 'account.create', 'destroy' => 'account.delete'],
        'JournalTemplateController' => ['index' => 'template.read', 'show' => 'template.read', 'store' => 'template.create', 'update' => 'template.update', 'destroy' => 'template.delete', 'bookmark' => 'template.bookmark'],
        'PeriodController' => ['index' => 'period.read', 'show' => 'period.read', 'store' => 'period.create', 'update' => 'period.update', 'destroy' => 'period.delete', 'close' => 'period.close', 'reopen' => 'period.reopen', 'deactivate' => 'period.deactivate'],
        'RecurringJournalController' => ['index' => 'recurring.read', 'show' => 'recurring.read', 'store' => 'recurring.create', 'update' => 'recurring.update', 'destroy' => 'recurring.delete', 'pause' => 'recurring.update', 'resume' => 'recurring.update', 'run' => 'recurring.run'],
        'JournalController' => ['index' => 'journal.read', 'show' => 'journal.read', 'nextNumber' => 'journal.read', 'nextTransactionCode' => 'journal.read', 'store' => 'journal.create', 'update' => 'journal.update', 'previewAccountReplacement' => 'journal.update', 'replaceAccountsBatch' => 'journal.update', 'destroy' => 'journal.delete', 'restore' => 'journal.delete', 'post' => 'journal.post', 'submit' => 'journal.submit', 'cancelReview' => 'journal.submit', 'reject' => 'journal.review', 'reverse' => 'journal.reverse', 'replicate' => 'journal.create'],
        'AttachmentController' => ['index' => 'attachment.read', 'show' => 'attachment.read', 'store' => 'attachment.upload', 'destroy' => 'attachment.delete'],
        'AutoMappingController' => ['index' => 'automapping.read', 'show' => 'automapping.read', 'saveRule' => 'automapping.manage', 'reprocess' => 'automapping.manage'],
        'FiscalAdjustmentController' => ['index' => 'fiscal.adjustment.read', 'show' => 'fiscal.adjustment.read', 'store' => 'fiscal.adjustment.manage', 'update' => 'fiscal.adjustment.manage', 'destroy' => 'fiscal.adjustment.manage', 'approve' => 'fiscal.adjustment.approve'],
        'TaxProvisionController' => ['show' => 'fiscal.tax_provision.read', 'preview' => 'fiscal.tax_provision.read', 'store' => 'fiscal.tax_provision.manage'],
        'SchedulerHealthController' => ['index' => 'settings.cron.manage'],
        'OnboardingController' => ['coaTemplates' => 'account.read', 'applyCoa' => 'settings.coa_template.manage', 'bookkeepingMode' => 'workspace.manage'],
        'FakeDataController' => ['index' => 'settings.fake_data.manage', 'resetPreview' => 'settings.fake_data.manage', 'reset' => 'settings.fake_data.manage', 'importAll' => 'settings.fake_data.manage', 'import' => 'settings.fake_data.manage', 'destroy' => 'settings.fake_data.manage'],
        'FinancialPulseController' => ['show' => 'dashboard.read'],
        'RecentJournalsController' => ['index' => 'journal.read'],
        'SourceRefController' => ['index' => 'journal.read', 'bySourceRef' => 'report.read'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $action = $request->route()->getActionName();
        if (! str_contains($action, '@')) {
            return $next($request);
        }
        [$class, $method] = explode('@', $action);
        $controller = class_basename($class);
        $permission = $controller === 'ReportingController' ? 'report.read' : (self::ACTIONS[$controller][$method] ?? null);
        $accountUpdate = $controller === 'AccountController' && $method === 'update';
        if (! $permission && ! $accountUpdate) {
            return $next($request);
        }
        if ($controller === 'OnboardingController' && in_array($method, ['applyCoa', 'bookkeepingMode'], true)) {
            app(InstallationOnboardingService::class)->assertIncomplete();
        }
        $entity = $this->resolveEntity($request);
        if ($controller === 'SourceRefController' && $method === 'index'
            && Gate::allows('accounting.permission', ['report.read', $entity])) {
            return $next($request);
        }
        if ($accountUpdate) {
            $account = Account::where('entity_id', $entity->id)->findOrFail($request->route('id'));
            Gate::authorize('accounting.permission', ['account.read', $entity]);
            foreach ($request->only($account->getFillable()) as $field => $value) {
                if ($field === 'entity_id') {
                    continue;
                }
                $current = $account->getAttribute($field);
                if (in_array($field, ['is_active', 'is_postable'], true)) {
                    $value = (bool) $value;
                    $current = (bool) $current;
                }
                if (($value === '' ? null : $value) === ($current === '' ? null : $current)) {
                    continue;
                }
                Gate::authorize('accounting.permission', [match ($field) {
                    'name' => 'account.rename', 'is_active' => 'account.activate', default => 'account.update',
                }, $entity]);
            }
        } else {
            Gate::authorize('accounting.permission', [$permission, $entity]);
        }
        if ($controller === 'RecurringJournalController') {
            $autoPost = $request->boolean('auto_post')
                || (in_array($method, ['run', 'resume'], true) && RecurringJournal::where('entity_id', $entity->id)
                    ->whereKey($request->route('id'))->where('auto_post', true)->exists());
            if ($autoPost) {
                Gate::authorize('accounting.permission', ['journal.post', $entity]);
            }
        }

        return $next($request);
    }
}
