<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\CoaTemplate;
use App\Models\CoaTemplateAccount;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\User;
use App\Support\Tenancy\TenantOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Shared by the two-organisation isolation tests (stage 8): the fixture, the route sweeps, and helpers.
 * Organisation A is the target; organisation B is the attacker.
 */
const MARK = 'MARK-';

/** The beforeEach hook: two complete organisations, A and B. */
function twoOrganizationFixture(): Closure
{
    return function () {
        Storage::fake('local');
        $this->rbacApp = RbacApp::query()->firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'version' => '1.0', 'enabled' => true]);
        $this->operator = Role::query()->firstOrCreate(['tenant_id' => null, 'code' => 'operator'], ['name' => 'Operator', 'is_preset' => true]);
        $this->inspector = Role::query()->firstOrCreate(['tenant_id' => null, 'code' => 'inspector'], ['name' => 'Inspector', 'is_preset' => true]);

        $row = fn (string $table, array $values): string => (function () use ($table, $values): string {
            $id = (string) Str::ulid();
            DB::table($table)->insert(['id' => $id] + $values);

            return $id;
        })();

        foreach (['A', 'B'] as $x) {
            $mark = MARK.$x;
            $tenant = Tenant::query()->create([
                'name' => "Org {$x}", 'slug' => 'org-'.strtolower($x).'-'.uniqid(), 'status' => 'active',
                'ecopa_organization_id' => 'org-'.strtolower($x), 'ecopa_organization_slug' => 'org-'.strtolower($x),
                'ecopa_organization_linked_at' => now(), 'onboarding_completed_at' => now(),
            ]);
            $entity = Entity::query()->create([
                'tenant_id' => $tenant->id, 'name' => "PT {$mark}", 'workspace_settings' => ['bookkeeping_mode' => 'independent_books'],
            ]);
            $period = Period::query()->create(['entity_id' => $entity->id, 'name' => "Periode {$mark}", 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
            $cash = Account::query()->create(['entity_id' => $entity->id, 'code' => '1101', 'name' => "Kas {$mark}", 'type' => 'asset', 'normal_balance' => 'debit', 'availability' => 'both']);
            $revenue = Account::query()->create(['entity_id' => $entity->id, 'code' => '4101', 'name' => "Penjualan {$mark}", 'type' => 'revenue', 'normal_balance' => 'credit', 'availability' => 'both']);

            $journals = [];
            foreach (['internal', 'fiscal'] as $book) {
                $journal = Journal::query()->create([
                    'entity_id' => $entity->id, 'period_id' => $period->id, 'type' => 'general', 'journal_mode' => $book,
                    'number' => "J-{$x}-{$book}", 'date' => '2026-02-01', 'memo' => "Memo {$book} {$mark}", 'status' => Journal::STATUS_POSTED,
                    'idempotency_key' => "idem-{$x}-{$book}",
                ]);
                JournalEntry::query()->create(['journal_id' => $journal->id, 'line_no' => 1, 'account_id' => $cash->id, 'debit' => '1500.00', 'credit' => '0', 'memo' => "Baris {$mark}"]);
                JournalEntry::query()->create(['journal_id' => $journal->id, 'line_no' => 2, 'account_id' => $revenue->id, 'debit' => '0', 'credit' => '1500.00']);
                $journals[$book] = $journal;
            }
            $draft = Journal::query()->create([
                'entity_id' => $entity->id, 'period_id' => $period->id, 'type' => 'general', 'journal_mode' => 'internal',
                'number' => "J-{$x}-draft", 'date' => '2026-02-02', 'memo' => "Draft {$mark}", 'status' => Journal::STATUS_DRAFT,
            ]);

            $path = "attachments/{$tenant->id}/{$entity->id}/2026/02/bukti-{$x}.pdf";
            Storage::disk('local')->put($path, "isi rahasia {$mark}");
            $attachment = $row('attachments', [
                'tenant_id' => $tenant->id, 'attachable_type' => Journal::class, 'attachable_id' => $journals['internal']->id, 'entity_id' => $entity->id,
                'filename' => "bukti-{$mark}.pdf", 'path' => $path, 'disk' => 'local', 'size_bytes' => 20,
            ]);
            $adjustment = $row('fiscal_adjustments', [
                'tenant_id' => $tenant->id, 'entity_id' => $entity->id, 'account_id' => $cash->id, 'date' => '2026-02-28',
                'direction' => 'increase', 'amount' => '250.00', 'reason' => "Koreksi {$mark}",
            ]);
            $template = $row('journal_templates', ['tenant_id' => $tenant->id, 'entity_id' => $entity->id, 'code' => 'T1', 'name' => "Templat {$mark}"]);
            $row('journal_template_lines', ['tenant_id' => $tenant->id, 'template_id' => $template, 'line_no' => 1, 'account_id' => $cash->id, 'side' => 'debit']);
            $recurring = $row('recurring_journals', [
                'tenant_id' => $tenant->id, 'entity_id' => $entity->id, 'template_id' => $template, 'name' => "Berulang {$mark}",
                'frequency' => 'monthly', 'start_date' => '2026-01-01', 'next_run_at' => '2026-01-01', 'status' => 'active',
            ]);
            $rule = $row('auto_mapping_rules', ['tenant_id' => $tenant->id, 'entity_id' => $entity->id, 'source_type' => 'sales', 'name' => "Aturan {$mark}", 'structure_hash' => "h{$x}", 'mapping' => '{}']);
            $raw = $row('auto_mapping_raw_data', ['tenant_id' => $tenant->id, 'entity_id' => $entity->id, 'source_type' => 'sales', 'structure_hash' => "h{$x}", 'payload' => json_encode(['rahasia' => $mark])]);
            $note = $row('documentation_notes', ['tenant_id' => $tenant->id, 'entity_id' => $entity->id, 'title' => "Catatan {$mark}"]);
            $row('tax_codes', ['tenant_id' => $tenant->id, 'entity_id' => $entity->id, 'code' => 'PPN', 'name' => "PPN {$mark}", 'kind' => 'vat']);

            $subscription = $row('webhook_subscriptions', [
                'tenant_id' => $tenant->id, 'entity_id' => $entity->id, 'event' => '*', 'url' => "https://hook.example.test/{$x}", 'secret' => "secret-{$x}", 'is_active' => true, 'is_inbound' => false,
            ]);
            $globalSubscription = $row('webhook_subscriptions', [
                'tenant_id' => $tenant->id, 'entity_id' => null, 'event' => '*', 'url' => "https://hook.example.test/{$x}-global", 'secret' => "secret-{$x}-g", 'is_active' => true, 'is_inbound' => false,
            ]);
            $coaTemplate = CoaTemplate::query()->create(['tenant_id' => $tenant->id, 'name' => "Template {$mark}", 'source_type' => 'entity', 'source_label' => $mark]);
            CoaTemplateAccount::query()->create(['tenant_id' => $tenant->id, 'template_id' => $coaTemplate->id, 'code' => '1101', 'name' => "Kas {$mark}", 'type' => 'asset', 'normal_balance' => 'debit']);
            $customRole = Role::query()->create(['tenant_id' => $tenant->id, 'code' => 'staf-'.strtolower($x), 'name' => "Staf {$mark}"]);

            $person = fn (string $kind): User => User::query()->create([
                'name' => "{$kind} {$mark}", 'email' => strtolower("{$kind}-{$x}-").uniqid().'@example.test', 'main_tier_user_id' => "ecopa-{$kind}-{$x}-".uniqid(), 'email_verified_at' => now(),
            ]);
            $owner = $person('owner');
            $member = $person('member');
            $inspector = $person('inspector');
            $assign = fn (User $user, Role $role) => UserAppAssignment::query()->create([
                'user_id' => $user->id, 'app_id' => $this->rbacApp->id, 'entity_id' => $entity->id, 'role_id' => $role->id, 'tenant_id' => $tenant->id, 'assigned_at' => now(),
            ]);
            $assign($member, $this->operator);
            $memberAssignment = $assign($inspector, $this->inspector);
            $waiting = $person('waiting');
            UserAppAssignment::query()->create(['user_id' => $waiting->id, 'app_id' => $this->rbacApp->id, 'entity_id' => null, 'role_id' => null, 'tenant_id' => $tenant->id, 'assigned_at' => now()]);

            [$token] = ApiToken::issue(['name' => "Bot {$mark}", 'tenant_id' => $tenant->id, 'user_id' => null, 'app_id' => $this->rbacApp->id, 'permissions' => ['journal.create', 'journal.post', 'journal.read', 'automapping.ingest']]);
            $plain = ApiToken::issue(['name' => "Bot2 {$mark}", 'tenant_id' => $tenant->id, 'user_id' => null, 'app_id' => $this->rbacApp->id, 'permissions' => ['journal.create', 'journal.post']])[1];

            $this->{$x} = (object) [
                'mark' => $mark, 'tenant' => $tenant, 'entity' => $entity, 'period' => $period, 'cash' => $cash, 'revenue' => $revenue,
                'journal' => $journals['internal'], 'fiscalJournal' => $journals['fiscal'], 'draft' => $draft, 'attachment' => $attachment,
                'adjustment' => $adjustment, 'template' => $template, 'recurring' => $recurring, 'rule' => $rule, 'raw' => $raw, 'note' => $note,
                'subscription' => $subscription, 'globalSubscription' => $globalSubscription, 'coaTemplate' => $coaTemplate, 'customRole' => $customRole,
                'owner' => $owner, 'member' => $member, 'inspectorUser' => $inspector, 'assignment' => $memberAssignment, 'waiting' => $waiting,
                'token' => $token, 'plainToken' => $plain, 'attachmentPath' => $path,
            ];
        }

        $this->session = fn (string $org, string $role) => [
            'ecopa.app_role' => $role, 'ecopa.platform_admin' => false, 'ecopa.organization' => ['id' => $org, 'slug' => $org, 'name' => $org],
        ];
        /** Sign in as someone, one request context at a time (a real request starts with no remembered user). */
        $this->actAs = function (User $user, string $org, string $role, ?string $entityId) {
            app('auth')->forgetGuards();
            $this->flushSession();
            $this->defaultHeaders = [];
            $this->actingAs($user)->withSession(($this->session)($org, $role));
            if ($entityId !== null) {
                $this->withHeader('X-Tenant-Slug', $entityId);
            }

            return $this;
        };

        /** A cheap fingerprint of every tenant-owned row of a tenant (and its access rows). */
        $this->fingerprint = function (string $tenantId): array {
            $print = [];
            foreach ([...TenantOwnership::tables(), 'entities', 'roles', 'user_app_assignments', 'auto_mapping_entity_links'] as $table) {
                $print[$table] = md5(json_encode(DB::table($table)->where('tenant_id', $tenantId)->orderBy($table === 'audit_log' ? 'id' : 'id')->get()->all()));
            }
            $print['users'] = md5(json_encode(DB::table('users')->orderBy('id')->get(['id', 'name', 'email', 'disabled_at'])->all()));
            $print['storage'] = md5(json_encode(collect(Storage::disk('local')->allFiles())->filter(fn ($p) => str_contains($p, $tenantId))->sort()->values()->all()));

            return $print;
        };
    };
}

/**
 * Every SPA route, once per allowed method, as one actor of organisation B.
 *
 * @return list<array{method: string, uri: string, controller: string, params: list<string>}>
 */
function spaRoutes(): array
{
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/spa')) {
            continue;
        }
        preg_match_all('/\{(\w+)\??\}/', $route->uri(), $names);
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[] = [
                'method' => $method, 'uri' => $route->uri(), 'params' => $names[1],
                'controller' => class_basename(explode('@', $route->getActionName())[0]),
            ];
        }
    }

    return $routes;
}

/** An id of organisation A that the route's own {param} would take. */
function foreignParam(object $a, string $controller, string $param): string
{
    return match (true) {
        $controller === 'AutoMappingController' && $param === 'ruleId' => $a->rule,
        $controller === 'AutoMappingController' && $param === 'linkId' => (string) Str::ulid(),
        $controller === 'AutoMappingController' => $a->raw,
        $controller === 'RoleManagementController' => $a->assignment->id,
        $controller === 'PermissionManagementController' => $a->customRole->id,
        $controller === 'FakeDataController' => 'accounts',
        $controller === 'WorkspaceController' => $a->entity->id,
        $controller === 'CoaTemplateController' => $a->coaTemplate->id,
        $controller === 'JournalController' => $a->journal->id,
        $controller === 'AccountController' => $a->cash->id,
        $controller === 'AttachmentController' => $a->attachment,
        $controller === 'PeriodController' => $a->period->id,
        $controller === 'JournalTemplateController' => $a->template,
        $controller === 'RecurringJournalController' => $a->recurring,
        $controller === 'FiscalAdjustmentController' => $a->adjustment,
        $controller === 'WebhookSubscriptionController' => $a->subscription,
        $controller === 'DocumentationNoteController' => $a->note,
        default => $a->entity->id,
    };
}

/** Junk that points at A's things, for mutations and filters. */
function foreignBody(object $a): array
{
    return [
        'entity_id' => $a->entity->id, 'period_id' => $a->period->id, 'tenant_id' => $a->tenant->id, 'account_id' => $a->cash->id,
        'journal_id' => $a->journal->id, 'template_id' => $a->template, 'user_id' => $a->waiting->id, 'role_id' => $a->customRole->id,
        'role_code' => $a->customRole->code, 'assignment_ids' => [$a->assignment->id], 'workspace_ids' => [$a->entity->id],
        'entity_ids' => [$a->entity->id], 'template_key' => 'org:'.$a->coaTemplate->id, 'coa_template_key' => 'org:'.$a->coaTemplate->id,
        'name' => 'x', 'title' => 'x', 'code' => 'x', 'source' => 'entity', 'source_entity_id' => $a->entity->id,
        'confirmation_name' => $a->mark, 'date' => '2026-02-03', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
    ];
}

/**
 * @param  object  $a  organisation A's fixture (the target)
 * @return list<string> violations
 */
function sweepAs($test, object $a, string $label, ?string $header, bool $withForeignIds, bool $mayRead): array
{
    $violations = [];
    foreach (spaRoutes() as $route) {
        $uri = '/'.$route['uri'];
        foreach ($route['params'] as $param) {
            $uri = str_replace("{{$param}}", $withForeignIds ? foreignParam($a, $route['controller'], $param) : (string) Str::ulid(), $uri);
        }
        $body = foreignBody($a);
        $query = $route['method'] === 'GET' ? '?'.http_build_query(array_intersect_key($body, array_flip(['entity_id', 'period_id', 'tenant_id', 'account_id', 'journal_id']))) : '';
        $response = $test->json($route['method'], $uri.$query, $route['method'] === 'GET' ? [] : $body);
        $status = $response->getStatusCode();
        $where = "[{$label}] {$route['method']} {$route['uri']} -> {$status}";

        if (str_contains((string) $response->getContent(), $a->mark)) {
            $violations[] = "{$where}: response contains {$a->mark}";
        }
        if ($status >= 500) {
            $violations[] = "{$where}: server error";
        }
        // Naming A's resource must never succeed; with A's workspace in the header nothing entity-bound may succeed.
        // A fake-data {group} is a dataset name, not an id of someone's resource.
        $named = $route['params'] !== [] && $withForeignIds && $route['controller'] !== 'FakeDataController';
        if (($named || ! $mayRead) && $status >= 200 && $status < 300) {
            $violations[] = "{$where}: succeeded";
        }
    }

    return $violations;
}

/** @return list<array{method: string, uri: string, controller: string, params: list<string>}> */
function tokenRoutes(): array
{
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        $api = (str_starts_with($uri, 'api/v1/') && ! str_starts_with($uri, 'api/v1/spa') && $uri !== 'api/v1/me') || $uri === 'api/auto-mapping/ingest';
        if (! $api) {
            continue;
        }
        preg_match_all('/\{(\w+)\??\}/', $uri, $names);
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[] = ['method' => $method, 'uri' => $uri, 'params' => $names[1], 'controller' => class_basename(explode('@', $route->getActionName())[0])];
        }
    }

    return $routes;
}
