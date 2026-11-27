<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\UserAppAssignment;
use Akunta\Rbac\Support\TenantContext;
use App\Http\Middleware\VerifyEcopaSignature;
use App\Jobs\ProcessAutoMappingRawData;
use App\Jobs\PurgeArchivedWorkspace;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Journal;
use App\Models\User;
use App\Services\WebhookDispatcher;
use App\Support\Tenancy\TenantCache;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Prompt stage 8 - the security gate, part 2: every other door. Tokens, Ecopa
 * webhooks, outbound webhook dispatch, queued and scheduled work, attachments,
 * cache keys, and Role Management. Organisation A is the target, B the attacker
 * (see Concerns_two_organizations.php).
 */
require_once __DIR__.'/Concerns_two_organizations.php';

beforeEach(twoOrganizationFixture());

it('proves the sweep can see a leak: the same sweep against an organisation\'s OWN data is full of hits', function () {
    ($this->actAs)($this->B->owner, 'org-b', 'admin', $this->B->entity->id);

    $hits = sweepAs($this, $this->B, 'control', $this->B->entity->id, true, true);

    expect(count(spaRoutes()))->toBeGreaterThan(120)
        ->and(count(array_filter($hits, fn ($h) => str_contains($h, 'contains MARK-B'))))->toBeGreaterThan(5)
        ->and(count(array_filter($hits, fn ($h) => str_ends_with($h, 'succeeded'))))->toBeGreaterThan(10);
});

it('keeps the integration token of organisation B out of organisation A on every token API route', function () {
    $a = $this->A;
    $before = ($this->fingerprint)($a->tenant->id);
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->defaultHeaders = [];

    $body = foreignBody($a) + [
        'metadata' => ['source_app' => 'accounting'], 'idempotency_key' => 'b-token-key', 'source_type' => 'sales', 'payload' => ['x' => 1],
        'lines' => [['account_code' => '1101', 'debit' => 10, 'credit' => 0], ['account_code' => '4101', 'debit' => 0, 'credit' => 10]],
        'event' => '*', 'url' => 'https://evil.example.test/hook', 'frequency' => 'monthly',
    ];
    $body['journals'] = [$body + ['entity_id' => $a->entity->id]];

    $violations = [];
    $requests = 0;
    foreach (tokenRoutes() as $route) {
        $uri = '/'.$route['uri'];
        foreach ($route['params'] as $param) {
            $uri = str_replace("{{$param}}", match ($route['controller']) {
                'AccountBalanceController' => $a->cash->id, 'JournalTemplateController' => $a->template,
                'RecurringJournalController' => $a->recurring, 'WebhookSubscriptionController' => $a->subscription, default => $a->entity->id,
            }, $uri);
        }
        $response = $this->withToken($this->B->plainToken)->json($route['method'], $uri, $route['method'] === 'GET' ? [] : $body);
        $requests++;
        $status = $response->getStatusCode();
        $where = "{$route['method']} {$route['uri']} -> {$status}";
        if (str_contains((string) $response->getContent(), $a->mark)) {
            $violations[] = "{$where}: response contains {$a->mark}";
        }
        if ($status >= 500) {
            $violations[] = "{$where}: server error";
        }
        // Everything but a plain list names organisation A's workspace or resource and must not succeed.
        // (A bulk call answers 207 and reports per item: it succeeds only if some item did.)
        $succeeded = $status === 207 ? (int) $response->json('succeeded') > 0 : ($status >= 200 && $status < 300);
        if (($route['method'] !== 'GET' || $route['params'] !== []) && $succeeded) {
            $violations[] = "{$where}: succeeded";
        }
    }

    expect($requests)->toBeGreaterThan(20)->and($violations)->toBe([]);
    expect(($this->fingerprint)($a->tenant->id))->toBe($before);
});

it('answers the token lists of B with B\'s data only', function () {
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->defaultHeaders = [];

    foreach (['journal-templates', 'recurring-journals'] as $list) {
        $response = $this->withToken($this->B->plainToken)->getJson("/api/v1/{$list}")->assertOk();
        expect($response->getContent())->toContain($this->B->mark)->not->toContain($this->A->mark);
    }
    $urls = collect($this->withToken($this->B->plainToken)->getJson('/api/v1/webhooks')->assertOk()->json('data'))->pluck('url');
    expect($urls->all())->toBe(['https://hook.example.test/B', 'https://hook.example.test/B-global']);
});

function sharedPerson(string $id, string $org, array $extra = []): array
{
    return ['user_id' => $id, 'email' => "{$id}@example.test", 'name' => $id, 'organization' => ['id' => $org]] + $extra;
}

function orgWebhook(string $event, array $subject)
{
    return test()->withoutMiddleware(VerifyEcopaSignature::class)->postJson('/webhooks/ecopa', [
        'event' => $event, 'event_id' => 'evt-'.Str::uuid(), 'subject' => $subject,
    ]);
}

it('keeps Ecopa events of organisation B away from organisation A', function () {
    $a = $this->A;

    // One person who belongs to both organisations: B's events only ever touch B's grant.
    orgWebhook('user.assigned', sharedPerson('ecopa-both', 'org-a', ['ecopa_role' => 'member']))->assertOk();
    orgWebhook('user.assigned', sharedPerson('ecopa-both', 'org-b', ['ecopa_role' => 'admin']))->assertOk();
    $both = User::query()->where('main_tier_user_id', 'ecopa-both')->sole();
    $grantA = fn () => UserAppAssignment::withoutGlobalScopes()->where('user_id', $both->id)->where('tenant_id', $a->tenant->id)->sole();
    $snapshotA = $grantA()->only(['ecopa_role', 'revoked_at', 'role_id', 'entity_id']);
    $before = ($this->fingerprint)($a->tenant->id);

    orgWebhook('app.access.revoked', sharedPerson('ecopa-both', 'org-b') + ['app' => ['slug' => 'accounting']])->assertOk();
    orgWebhook('user.revoked', sharedPerson('ecopa-both', 'org-b'))->assertOk();
    orgWebhook('app_permission.role_changed', sharedPerson('ecopa-both', 'org-b'))->assertOk();
    expect($grantA()->only(['ecopa_role', 'revoked_at', 'role_id', 'entity_id']))->toBe($snapshotA);

    // B cannot reach into A's workspace, rename it, re-home it, or grant or take access inside it.
    orgWebhook('entity.updated', ['id' => $a->entity->id, 'name' => 'Dibajak', 'organization' => ['id' => 'org-b']])
        ->assertStatus(422)->assertJsonPath('code', 'entity_tenant_mismatch');
    orgWebhook('assignment.granted', ['user_id' => 'ecopa-both', 'entity_id' => $a->entity->id, 'app_code' => 'accounting', 'ecopa_role' => 'admin', 'organization' => ['id' => 'org-b']])
        ->assertStatus(422)->assertJsonPath('code', 'entity_tenant_mismatch');
    orgWebhook('assignment.revoked', ['user_id' => $a->member->main_tier_user_id, 'entity_id' => $a->entity->id, 'app_code' => 'accounting', 'organization' => ['id' => 'org-b']])
        ->assertStatus(422);
    orgWebhook('app.admin_bootstrap', ['app' => ['slug' => 'accounting'], 'admins' => [sharedPerson('ecopa-intruder', 'org-b') + ['id' => 'ecopa-intruder']]])->assertOk();
    $intruder = User::query()->where('main_tier_user_id', 'ecopa-intruder')->first();
    expect($intruder === null || UserAppAssignment::withoutGlobalScopes()->where('user_id', $intruder->id)->where('tenant_id', $a->tenant->id)->doesntExist())->toBeTrue();

    // A's members and tokens survive B's revocations; nothing of A changed.
    expect(UserAppAssignment::withoutGlobalScopes()->where('user_id', $a->member->id)->whereNull('revoked_at')->exists())->toBeTrue()
        ->and(ApiToken::withoutGlobalScopes()->where('tenant_id', $a->tenant->id)->whereNotNull('revoked_at')->count())->toBe(0)
        ->and(Entity::withoutGlobalScopes()->find($a->entity->id)->name)->toBe("PT {$a->mark}");
    $after = ($this->fingerprint)($a->tenant->id);
    foreach (['accounts', 'journals', 'journal_entries', 'periods', 'attachments', 'entities', 'webhook_subscriptions', 'api_tokens', 'roles'] as $table) {
        expect($after[$table])->toBe($before[$table], $table);
    }
});

it('delivers a journal event only to the subscriptions of its own organisation', function () {
    // The dispatcher is called the way listeners call it: from a request, a job, or a command.
    Queue::fake();
    $dispatch = fn (object $org): int => app(WebhookDispatcher::class)->dispatch('journal.posted', ['memo' => $org->mark], $org->entity->id);

    // With no context at all, an event of B must not reach A's global subscription.
    expect($dispatch($this->B))->toBe(2);
    expect(DB::table('webhook_deliveries')->pluck('tenant_id')->unique()->all())->toBe([$this->B->tenant->id]);

    // Even from inside another organisation's context an event of B reaches nobody in A (and, since
    // that context cannot see B's subscriptions either, nobody at all).
    TenantContext::run($this->A->tenant->id, fn () => expect($dispatch($this->B))->toBe(0));
    expect(DB::table('webhook_deliveries')->where('tenant_id', $this->A->tenant->id)->count())->toBe(0)
        ->and(DB::table('webhook_deliveries')->where('tenant_id', $this->B->tenant->id)->count())->toBe(2);

    TenantContext::run($this->A->tenant->id, fn () => expect($dispatch($this->A))->toBe(2));
    expect(DB::table('webhook_deliveries')->where('tenant_id', $this->A->tenant->id)->count())->toBe(2);
});

it('does not let a queued job of one organisation touch another organisation\'s rows', function () {
    $before = ($this->fingerprint)($this->A->tenant->id);

    // A's raw event id is handed to a job running for B: B's context cannot see it, so nothing is processed.
    $rawStatus = DB::table('auto_mapping_raw_data')->where('id', $this->A->raw)->value('status');
    TenantContext::run($this->B->tenant->id, function () {
        $job = new ProcessAutoMappingRawData($this->A->raw);
        expect($job->tenantId)->toBe($this->B->tenant->id);
        expect(fn () => app()->call([$job, 'handle']))->toThrow(ModelNotFoundException::class);
    });
    expect(DB::table('auto_mapping_raw_data')->where('id', $this->A->raw)->value('status'))->toBe($rawStatus);

    // An archived workspace of A cannot be purged by a job dispatched inside B's context.
    DB::table('entities')->where('id', $this->A->entity->id)->update(['archived_at' => now()->subYears(2)]);
    TenantContext::run($this->B->tenant->id, function () {
        $job = new PurgeArchivedWorkspace($this->A->entity->id, ignoreRetention: true);
        app()->call([$job, 'handle']);
    });
    expect(DB::table('entities')->where('id', $this->A->entity->id)->exists())->toBeTrue();

    // A job carries its own tenant when it is created outside any request.
    expect((new PurgeArchivedWorkspace($this->A->entity->id))->tenantId)->toBe($this->A->tenant->id);
    DB::table('entities')->where('id', $this->A->entity->id)->update(['archived_at' => null]);
    expect(($this->fingerprint)($this->A->tenant->id))->toBe($before);
});

it('runs scheduled work per organisation and never crosses them', function () {
    // A recurring schedule is due in both organisations.
    Artisan::call('accounting:run-recurring', ['--date' => '2026-03-01', '--dry-run' => true]);
    $dry = Artisan::output();
    $blocks = collect(explode('Tenant ', $dry))->filter()->mapWithKeys(fn (string $block): array => [trim(strtok($block, "\n")) => $block]);
    expect($blocks[$this->A->tenant->id])->toContain($this->A->recurring)->not->toContain($this->B->recurring)
        ->and($blocks[$this->B->tenant->id])->toContain($this->B->recurring)->not->toContain($this->A->recurring);

    // Real run: every journal it creates sits in the entity (and tenant) of the schedule that made it.
    Artisan::call('accounting:run-recurring', ['--date' => '2026-03-01']);
    foreach ([$this->A, $this->B] as $org) {
        $created = Journal::withoutGlobalScopes()->where('source_app', 'recurring')->orWhere('idempotency_key', 'like', 'rec:%')->get()
            ->filter(fn (Journal $j) => $j->entity_id === $org->entity->id);
        $created->each(fn (Journal $j) => expect($j->getAttributes()['tenant_id'])->toBe($org->tenant->id));
    }
    expect(Journal::withoutGlobalScopes()->whereNull('tenant_id')->count())->toBe(0)
        ->and(Journal::withoutGlobalScopes()->join('entities', 'entities.id', '=', 'journals.entity_id')->whereColumn('journals.tenant_id', '<>', 'entities.tenant_id')->count())->toBe(0)
        ->and(TenantContext::id())->toBeNull();
});

it('keeps attachments of one organisation out of reach of another, by id, by path, and by upload', function () {
    $a = $this->A;
    $before = ($this->fingerprint)($a->tenant->id);

    ($this->actAs)($this->B->owner, 'org-b', 'admin', $this->B->entity->id);
    // By id: another organisation's attachment does not exist for B.
    $this->getJson("/api/v1/spa/attachments/{$a->attachment}")->assertNotFound();
    $this->deleteJson("/api/v1/spa/attachments/{$a->attachment}")->assertNotFound();
    // Attaching to A's journal, or uploading into A's workspace, is refused.
    $file = fn () => UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf');
    $this->post('/api/v1/spa/attachments', ['file' => $file(), 'attachable_type' => Journal::class, 'attachable_id' => $a->journal->id], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('attachable_id');
    ($this->actAs)($this->B->owner, 'org-b', 'admin', $a->entity->id);
    $this->post('/api/v1/spa/attachments', ['file' => $file(), 'attachable_type' => Journal::class, 'attachable_id' => $this->B->journal->id], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    // A's own owner can read it, and B's own upload lands under B's tenant, never under A's.
    ($this->actAs)($this->B->owner, 'org-b', 'admin', $this->B->entity->id);
    $uploaded = $this->post('/api/v1/spa/attachments', ['file' => $file(), 'attachable_type' => Journal::class, 'attachable_id' => $this->B->journal->id], ['Accept' => 'application/json']);
    if ($uploaded->getStatusCode() === 201) {
        $path = DB::table('attachments')->where('id', $uploaded->json('data.id'))->value('path');
        expect($path)->toStartWith("attachments/{$this->B->tenant->id}/{$this->B->entity->id}/")->not->toContain($a->tenant->id);
    }

    expect(Storage::disk('local')->exists($a->attachmentPath))->toBeTrue()
        ->and(collect(Storage::disk('local')->allFiles())->filter(fn ($p) => str_contains($p, $this->B->tenant->id))->every(fn ($p) => ! str_contains($p, $a->tenant->id)))->toBeTrue()
        ->and(($this->fingerprint)($a->tenant->id))->toBe($before);
});

it('namespaces cache keys per organisation', function () {
    expect(TenantCache::key('dashboard', $this->A->tenant->id))->not->toBe(TenantCache::key('dashboard', $this->B->tenant->id));

    TenantContext::run($this->A->tenant->id, function () {
        cache()->put(TenantCache::key('summary'), 'rahasia A', 60);
    });
    TenantContext::run($this->B->tenant->id, fn () => expect(cache()->get(TenantCache::key('summary')))->toBeNull());
    expect(fn () => TenantCache::key('summary'))->toThrow(LogicException::class);
});

it('keeps Role Management inside the acting organisation', function () {
    $a = $this->A;
    $before = ($this->fingerprint)($a->tenant->id);

    ($this->actAs)($this->B->owner, 'org-b', 'admin', $this->B->entity->id);
    $list = $this->getJson('/api/v1/spa/role-management')->assertOk();
    $people = collect($list->json('data.users'))->pluck('user_id')->merge(collect($list->json('data.unassigned_users'))->pluck('user_id'));
    expect($people->all())->not->toContain($a->member->id, $a->waiting->id, $a->inspectorUser->id)
        ->and($people->all())->toContain($this->B->member->id)
        ->and($list->getContent())->not->toContain($a->mark);

    // B's owner cannot grant, edit, bulk-edit, or impersonate A's people or assignments — nor pull in A's person by id.
    $this->postJson('/api/v1/spa/role-management/assignments', ['user_id' => $a->waiting->id, 'role_id' => $this->operator->id, 'entity_id' => $this->B->entity->id])
        ->assertStatus(422);
    $this->patchJson("/api/v1/spa/role-management/{$a->assignment->id}", ['role_id' => $this->operator->id])->assertNotFound();
    $this->postJson("/api/v1/spa/role-management/{$a->assignment->id}/impersonate")->assertNotFound();
    $this->postJson('/api/v1/spa/role-management/bulk-assign', ['assignment_ids' => [$a->assignment->id], 'role_code' => 'operator'])->assertStatus(422);

    // Permission Management: B sees and changes only B's roles.
    $roles = $this->getJson('/api/v1/spa/permission-management')->assertOk();
    expect($roles->getContent())->not->toContain($a->mark)->not->toContain($a->customRole->id);
    $revision = $roles->json('data.revision');
    foreach ([
        $this->patchJson("/api/v1/spa/permission-management/roles/{$a->customRole->id}", ['name' => 'Dibajak', 'revision' => $revision]),
        $this->deleteJson("/api/v1/spa/permission-management/roles/{$a->customRole->id}", ['revision' => $revision]),
    ] as $response) {
        expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
    }

    expect(Role::withoutGlobalScopes()->find($a->customRole->id)->name)->toBe("Staf {$a->mark}")
        ->and(($this->fingerprint)($a->tenant->id))->toBe($before);
});

it('refuses a webhook subscription that B\'s token tries to put on A\'s workspace, and keeps its own in B', function () {
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->defaultHeaders = [];
    $subscriptions = fn (string $tenantId) => DB::table('webhook_subscriptions')->where('tenant_id', $tenantId)->count();
    $before = [$subscriptions($this->A->tenant->id), $subscriptions($this->B->tenant->id)];

    // Regression: the entity used to be taken on trust, so the subscription landed in A and received A's journal events.
    $this->withToken($this->B->plainToken)->postJson('/api/v1/webhooks', ['entity_id' => $this->A->entity->id, 'event' => 'journal.posted', 'url' => 'https://evil.example.test/steal'])
        ->assertStatus(422)->assertJsonPath('error', 'entity_not_found');
    expect($subscriptions($this->A->tenant->id))->toBe($before[0]);

    // Without a workspace it is a global subscription of B's own tenant (never tenant-less, never A's).
    $id = $this->withToken($this->B->plainToken)->postJson('/api/v1/webhooks', ['event' => 'journal.posted', 'url' => 'https://ok.example.test/hook'])->assertCreated()->json('id');
    expect(DB::table('webhook_subscriptions')->where('id', $id)->value('tenant_id'))->toBe($this->B->tenant->id)
        ->and($subscriptions($this->B->tenant->id))->toBe($before[1] + 1);
});

it('refuses to create a row of one organisation while working for another', function () {
    $before = ($this->fingerprint)($this->A->tenant->id);

    TenantContext::run($this->B->tenant->id, function () {
        // Naming A's workspace as the parent, or A's tenant outright, is refused whatever the controller did.
        expect(fn () => Account::query()->create(['entity_id' => $this->A->entity->id, 'code' => '9999', 'name' => 'Selundupan', 'type' => 'asset', 'normal_balance' => 'debit']))
            ->toThrow(AuthorizationException::class);
        // tenant_id is not mass-assignable, and setting it directly to A's is refused too.
        expect(function () {
            $account = new Account(['entity_id' => $this->B->entity->id, 'code' => '9998', 'name' => 'Selundupan', 'type' => 'asset', 'normal_balance' => 'debit']);
            $account->tenant_id = $this->A->tenant->id;
            $account->save();
        })->toThrow(AuthorizationException::class);
        // The same rows are fine for the organisation they belong to.
        Account::query()->create(['entity_id' => $this->B->entity->id, 'code' => '9997', 'name' => 'Sah', 'type' => 'asset', 'normal_balance' => 'debit']);
    });

    expect(($this->fingerprint)($this->A->tenant->id))->toBe($before)
        ->and(Account::withoutGlobalScopes()->whereIn('code', ['9999', '9998'])->count())->toBe(0);
});
