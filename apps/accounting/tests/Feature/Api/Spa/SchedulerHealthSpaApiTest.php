<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Permission;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Console\Commands\SchedulerHeartbeatCommand;
use App\Models\CronRunLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::forget(SchedulerHeartbeatCommand::CACHE_KEY);

    $tenant = Tenant::create(['name' => 'Scheduler Tenant', 'slug' => 'scheduler-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Scheduler Co']);
    $this->user = User::create([
        'name' => 'Scheduler Admin',
        'email' => 'scheduler-'.uniqid().'@x.test',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create([
        'code' => 'scheduler-'.uniqid(),
        'name' => 'Scheduler App',
        'version' => '0.1',
        'enabled' => true,
    ]);
    $role = Role::create([
        'code' => 'scheduler-admin-'.uniqid(),
        'name' => 'Scheduler Admin',
        'is_preset' => false,
    ]);
    $role->permissions()->attach(
        Permission::create([
            'app_id' => $app->id,
            'code' => 'settings.cron.manage',
        ])->id,
    );
    $this->user->assignments()->create([
        'entity_id' => $this->entity->id,
        'app_id' => $app->id,
        'role_id' => $role->id,
    ]);
});

it('reports missing scheduler heartbeats and failed scheduled jobs', function () {
    CronRunLog::create([
        'command' => 'accounting:prune-trashed-journals',
        'started_at' => now()->subDay(),
        'finished_at' => now()->subDay()->addSecond(),
        'exit_code' => 1,
        'failed' => true,
        'exception' => 'Storage unavailable',
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/scheduler/status')
        ->assertOk()
        ->assertJsonPath('data.scheduler.healthy', false)
        ->assertJsonPath('data.scheduler.last', null)
        ->assertJsonPath('data.scheduler.working_directory', base_path())
        ->assertJsonPath(
            'data.scheduler.cron_command',
            "* * * * * cd '".base_path()."' && php artisan schedule:run >> /dev/null 2>&1",
        )
        ->assertJsonPath('data.tasks.0.key', 'prune_trashed_journals')
        ->assertJsonPath('data.tasks.0.status', 'failed')
        ->assertJsonPath('data.tasks.0.last_failure.exception', 'Storage unavailable');
});

it('reports a healthy heartbeat and successful scheduled job', function () {
    Carbon::setTestNow('2026-10-02 01:00:00');
    $this->artisan('accounting:scheduler-heartbeat')->assertSuccessful();
    foreach (range(1, 120) as $minute) {
        CronRunLog::create([
            'command' => 'accounting:scheduler-heartbeat',
            'started_at' => now()->subMinutes($minute),
            'finished_at' => now()->subMinutes($minute)->addSecond(),
            'exit_code' => 0,
            'failed' => false,
        ]);
    }
    CronRunLog::create([
        'command' => 'accounting:prune-trashed-journals',
        'started_at' => now()->subMinutes(30),
        'finished_at' => now()->subMinutes(29),
        'exit_code' => 0,
        'failed' => false,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/scheduler/status')
        ->assertOk()
        ->assertJsonPath('data.scheduler.healthy', true)
        ->assertJsonPath('data.tasks.0.status', 'healthy')
        ->assertJsonPath('data.tasks.0.last_run.failed', false);

    Carbon::setTestNow();
});
