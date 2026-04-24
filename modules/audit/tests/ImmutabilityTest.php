<?php

declare(strict_types=1);

use Akunta\Audit\Exceptions\ImmutableAuditException;
use Akunta\Audit\Models\AuditLog;
use Akunta\Core\Contracts\AuditLogger;

function insertRow(): AuditLog
{
    $id = app(AuditLogger::class)->record('journal.post', 'Journal', 'jnl_01H');

    return AuditLog::findOrFail($id);
}

it('allows inserting a row', function () {
    $row = insertRow();

    expect($row->exists)->toBeTrue();
});

it('blocks update() at the Eloquent layer', function () {
    $row = insertRow();

    expect(fn () => $row->update(['action' => 'journal.tampered']))
        ->toThrow(ImmutableAuditException::class);
});

it('blocks save() on a loaded row', function () {
    $row = insertRow();
    $row->action = 'journal.tampered';

    expect(fn () => $row->save())
        ->toThrow(ImmutableAuditException::class);
});

it('blocks delete()', function () {
    $row = insertRow();

    expect(fn () => $row->delete())
        ->toThrow(ImmutableAuditException::class);
});

it('blocks forceDelete()', function () {
    $row = insertRow();

    expect(fn () => $row->forceDelete())
        ->toThrow(ImmutableAuditException::class);
});
