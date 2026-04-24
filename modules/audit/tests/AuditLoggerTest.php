<?php

declare(strict_types=1);

use Akunta\Audit\AuditLogger;
use Akunta\Audit\Models\AuditLog;
use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;

it('resolves AuditLogger from the container via the contract', function () {
    expect(app(AuditLoggerContract::class))->toBeInstanceOf(AuditLogger::class);
});

it('inserts an audit_log row and returns a ULID', function () {
    $id = app(AuditLoggerContract::class)->record(
        action: 'journal.post',
        resourceType: 'Journal',
        resourceId: 'jnl_01H',
    );

    expect($id)->toBeString()
        ->and(strlen($id))->toBe(26);

    $row = AuditLog::find($id);
    expect($row)->not->toBeNull()
        ->and($row->action)->toBe('journal.post')
        ->and($row->resource_type)->toBe('Journal')
        ->and($row->resource_id)->toBe('jnl_01H');
});

it('stores metadata as JSON and returns it cast as array', function () {
    $meta = ['debit' => 100, 'credit' => 100, 'memo' => 'test'];

    $id = app(AuditLoggerContract::class)->record(
        action: 'journal.post',
        resourceType: 'Journal',
        resourceId: 'jnl_01H',
        metadata: $meta,
    );

    expect(AuditLog::find($id)->metadata)->toBe($meta);
});

it('uses the passed actor_user_id when provided', function () {
    $id = app(AuditLoggerContract::class)->record(
        action: 'journal.post',
        resourceType: 'Journal',
        resourceId: 'jnl_01H',
        actorUserId: 'usr_explicit',
    );

    expect(AuditLog::find($id)->actor_user_id)->toBe('usr_explicit');
});

it('falls back to auth()->id() when actor_user_id is null', function () {
    $this->be(new class extends \Illuminate\Foundation\Auth\User
    {
        public function getAuthIdentifier(): string
        {
            return 'usr_from_auth';
        }
    });

    $id = app(AuditLoggerContract::class)->record(
        action: 'journal.post',
        resourceType: 'Journal',
        resourceId: 'jnl_01H',
    );

    expect(AuditLog::find($id)->actor_user_id)->toBe('usr_from_auth');
});

it('leaves actor_user_id null when neither param nor auth is set', function () {
    $id = app(AuditLoggerContract::class)->record(
        action: 'tenant.provision',
        resourceType: 'Tenant',
        resourceId: 'tnt_01H',
    );

    expect(AuditLog::find($id)->actor_user_id)->toBeNull();
});

it('accepts and stores entity_id', function () {
    $id = app(AuditLoggerContract::class)->record(
        action: 'journal.post',
        resourceType: 'Journal',
        resourceId: 'jnl_01H',
        entityId: 'ent_01H',
    );

    expect(AuditLog::find($id)->entity_id)->toBe('ent_01H');
});

it('truncates excessively long user_agent to 512 chars', function () {
    $longUa = str_repeat('x', 1000);

    $logger = new AuditLogger(
        app(\Illuminate\Contracts\Auth\Factory::class),
        \Illuminate\Http\Request::create('/', 'POST', server: ['HTTP_USER_AGENT' => $longUa]),
    );

    $id = $logger->record('journal.post', 'Journal', 'jnl_01H');

    expect(strlen(AuditLog::find($id)->user_agent))->toBe(512);
});
