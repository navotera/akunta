<?php

declare(strict_types=1);

use Akunta\ApiClient\AutoJournalClient;
use Akunta\ApiClient\Exceptions\ApiException;
use Akunta\ApiClient\Exceptions\AuthException;
use Akunta\ApiClient\Exceptions\DuplicateIdempotencyException;
use Akunta\ApiClient\Exceptions\ServerException;
use Akunta\ApiClient\Exceptions\ValidationException;
use Akunta\ApiClient\Responses\JournalResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->baseUrl = 'https://acc.test';
    $this->token = 'akt_test_'.str_repeat('x', 32);

    $this->makeClient = function (int $retries = 0): AutoJournalClient {
        return new AutoJournalClient(
            http: app(HttpFactory::class),
            baseUrl: $this->baseUrl,
            token: $this->token,
            timeoutSeconds: 1.0,
            retries: $retries,
            retryBaseDelayMs: 1,
        );
    };

    $this->validPayload = [
        'entity_id' => '01HYZ00000000000000000ENT1',
        'date' => '2026-04-15',
        'metadata' => ['source_app' => 'payroll', 'source_id' => 'run_42'],
        'idempotency_key' => 'payroll-run-42',
        'lines' => [
            ['account_code' => '1101', 'debit' => 100000, 'credit' => 0],
            ['account_code' => '4101', 'debit' => 0, 'credit' => 100000],
        ],
    ];
});

it('returns JournalResponse on 201 success', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response([
            'journal_id' => 'jnl_abc',
            'status' => 'posted',
            'audit_id' => 'aud_xyz',
        ], 201),
    ]);

    $client = ($this->makeClient)();

    $res = $client->postJournal($this->validPayload);

    expect($res)->toBeInstanceOf(JournalResponse::class)
        ->and($res->journalId)->toBe('jnl_abc')
        ->and($res->status)->toBe('posted')
        ->and($res->auditId)->toBe('aud_xyz');

    Http::assertSent(function ($request) {
        return $request->url() === $this->baseUrl.'/api/v1/journals'
            && $request->method() === 'POST'
            && $request->header('Authorization')[0] === 'Bearer '.$this->token
            && $request->header('Accept')[0] === 'application/json'
            && $request['idempotency_key'] === 'payroll-run-42';
    });
});

it('throws DuplicateIdempotencyException on 409 with existing_journal_id', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response([
            'error' => 'duplicate_idempotency_key',
            'existing_journal_id' => 'jnl_prev',
        ], 409),
    ]);

    $client = ($this->makeClient)();

    try {
        $client->postJournal($this->validPayload);
        $this->fail('Expected DuplicateIdempotencyException');
    } catch (DuplicateIdempotencyException $e) {
        expect($e->existingJournalId)->toBe('jnl_prev')
            ->and($e->status)->toBe(409)
            ->and($e->getMessage())->toBe('duplicate_idempotency_key');
    }
});

it('throws ValidationException on 422', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response([
            'error' => 'journal_invalid',
            'message' => 'Journal is unbalanced: debit 100 ≠ credit 99.',
        ], 422),
    ]);

    $client = ($this->makeClient)();

    expect(fn () => $client->postJournal($this->validPayload))
        ->toThrow(ValidationException::class, 'journal_invalid');
});

it('throws AuthException on 401', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response(['error' => 'token_invalid'], 401),
    ]);

    $client = ($this->makeClient)();

    expect(fn () => $client->postJournal($this->validPayload))
        ->toThrow(AuthException::class, 'token_invalid');
});

it('throws AuthException on 403', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response(['error' => 'insufficient_permissions'], 403),
    ]);

    $client = ($this->makeClient)();

    expect(fn () => $client->postJournal($this->validPayload))
        ->toThrow(AuthException::class, 'insufficient_permissions');
});

it('throws ServerException on 500 (no retry on 4xx/5xx body, only network)', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response(['error' => 'internal'], 500),
    ]);

    $client = ($this->makeClient)(retries: 0);

    expect(fn () => $client->postJournal($this->validPayload))
        ->toThrow(ServerException::class, 'internal');
});

it('retries on network errors then throws ServerException', function () {
    Http::fake(function () {
        throw new ConnectionException('dns fail');
    });

    $client = ($this->makeClient)(retries: 2);

    expect(fn () => $client->postJournal($this->validPayload))
        ->toThrow(ServerException::class, 'dns fail');
});

it('throws generic ApiException on unexpected non-2xx code', function () {
    Http::fake([
        $this->baseUrl.'/api/v1/journals' => Http::response(['error' => 'teapot'], 418),
    ]);

    $client = ($this->makeClient)();

    try {
        $client->postJournal($this->validPayload);
        $this->fail('Expected ApiException');
    } catch (ApiException $e) {
        expect($e)->not->toBeInstanceOf(AuthException::class)
            ->and($e)->not->toBeInstanceOf(ValidationException::class)
            ->and($e)->not->toBeInstanceOf(DuplicateIdempotencyException::class)
            ->and($e)->not->toBeInstanceOf(ServerException::class)
            ->and($e->status)->toBe(418);
    }
});

it('container binds AutoJournalClient from config', function () {
    config()->set('akunta-api-client.auto_journal', [
        'base_url' => 'https://container.test',
        'token' => 'akt_from_cfg',
        'timeout_seconds' => 5.0,
        'retries' => 1,
        'retry_base_delay_ms' => 10,
    ]);

    $client = app(AutoJournalClient::class);
    expect($client)->toBeInstanceOf(AutoJournalClient::class);

    Http::fake([
        'https://container.test/api/v1/journals' => Http::response(['journal_id' => 'x', 'status' => 'posted'], 201),
    ]);

    $res = $client->postJournal($this->validPayload);
    expect($res->journalId)->toBe('x');

    Http::assertSent(fn ($r) => $r->header('Authorization')[0] === 'Bearer akt_from_cfg');
});
