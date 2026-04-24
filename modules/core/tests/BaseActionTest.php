<?php

declare(strict_types=1);

use Akunta\Core\Actions\BaseAction;
use Akunta\Core\Contracts\AuditLogger;
use Akunta\Core\Exceptions\HookAbortException;
use Akunta\Core\HookManager;
use Akunta\Core\Hooks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->action = new class extends BaseAction
    {
        public int $transactionRuns = 0;

        public function runAuthorize(string $ability, mixed ...$args): void
        {
            $this->authorize($ability, ...$args);
        }

        public function runFireBefore(string $hook, mixed ...$payload): void
        {
            $this->fireBefore($hook, ...$payload);
        }

        public function runFireAfter(string $hook, mixed ...$payload): void
        {
            $this->fireAfter($hook, ...$payload);
        }

        public function runApplyFilter(string $hook, mixed $value, mixed ...$args): mixed
        {
            return $this->applyFilter($hook, $value, ...$args);
        }

        public function runInTx(Closure $work): mixed
        {
            $this->transactionRuns++;

            return $this->runInTransaction($work);
        }

        public function runAudit(string $action, string $type, string $id): string
        {
            return $this->audit($action, $type, $id);
        }
    };
});

it('authorize() passes when Gate allows', function () {
    Gate::define('journal.post', fn () => true);

    $this->action->runAuthorize('journal.post');
})->throwsNoExceptions();

it('authorize() throws when Gate denies', function () {
    Gate::define('journal.post', fn () => false);

    $this->action->runAuthorize('journal.post');
})->throws(AuthorizationException::class);

it('fireBefore() dispatches the hook event', function () {
    Event::fake();

    $this->action->runFireBefore(Hooks::JOURNAL_BEFORE_POST, ['jnl' => 1]);

    Event::assertDispatched(Hooks::JOURNAL_BEFORE_POST);
});

it('fireAfter() dispatches the hook event', function () {
    Event::fake();

    $this->action->runFireAfter(Hooks::JOURNAL_AFTER_POST, ['jnl' => 1]);

    Event::assertDispatched(Hooks::JOURNAL_AFTER_POST);
});

it('applyFilter() delegates to HookManager', function () {
    /** @var HookManager $hooks */
    $hooks = app('hooks');
    $hooks->addFilter('journal.data', fn (string $v) => $v . '!');

    expect($this->action->runApplyFilter('journal.data', 'hello'))->toBe('hello!');
});

it('runInTransaction() wraps the closure in DB::transaction', function () {
    $calls = 0;
    DB::shouldReceive('transaction')
        ->once()
        ->andReturnUsing(function (Closure $fn, int $attempts) use (&$calls) {
            $calls = $attempts;

            return $fn();
        });

    $result = $this->action->runInTx(fn () => 'done');

    expect($result)->toBe('done')
        ->and($calls)->toBe(1);
});

it('audit() delegates to the bound AuditLogger', function () {
    app()->bind(AuditLogger::class, fn () => new class implements AuditLogger
    {
        public function record(
            string $action,
            string $resourceType,
            string $resourceId,
            ?string $entityId = null,
            array $metadata = [],
            ?string $actorUserId = null,
        ): string {
            return 'aud_fixed';
        }
    });

    expect($this->action->runAudit('journal.post', 'Journal', 'jnl_1'))->toBe('aud_fixed');
});

it('HookAbortException carries the hook name and optional context', function () {
    $e = new HookAbortException('journal.before_post', 'SoD violation', ['reason' => 'same_user']);

    expect($e->hook)->toBe('journal.before_post')
        ->and($e->getMessage())->toBe('SoD violation')
        ->and($e->context)->toBe(['reason' => 'same_user']);
});

it('HookAbortException defaults message when empty', function () {
    $e = new HookAbortException('journal.before_post');

    expect($e->getMessage())->toBe('Action aborted by hook [journal.before_post].');
});
