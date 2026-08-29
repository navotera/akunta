<?php

declare(strict_types=1);

use App\Models\EcopaConfigIntegration;
use App\Models\EcopaWebhookLog;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ecopa.url', 'https://default-ecopa.example.test');
    config()->set('ecopa.registration_name', 'Akunta');
    config()->set('ecopa.registration_base_url', 'https://accounting.example.test');
    config()->set('ecopa.self_slug', 'accounting');
    config()->set('ecopa.client_id', null);
    config()->set('ecopa.client_secret', null);
    config()->set('ecopa.webhook_secret', null);

    $this->admin = User::create([
        'name' => 'Akunta Admin',
        'email' => 'akunta-admin@example.test',
        'password_hash' => bcrypt('secret'),
    ]);
});

it('exposes safe first-access setup metadata before login', function () {
    $this->get('/api/auth/integration-status')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('data.configured', false)
        ->assertJsonPath('data.registration_status', null)
        ->assertJsonPath('data.slug', 'accounting')
        ->assertJsonPath('data.base_url', 'https://accounting.example.test')
        ->assertJsonPath('data.webhook_url', 'https://accounting.example.test/webhooks/ecopa')
        ->assertJsonMissingPath('data.registration_token')
        ->assertJsonMissingPath('data.client_secret');
});

it('registers from the public first-access wizard without enabling integration prematurely', function () {
    Http::fake([
        'https://ecopa.example.test/api/app-registration-requests' => Http::response([
            'data' => ['id' => 'registration-123', 'status' => 'pending'],
        ], 202),
    ]);

    $this->postJson('/api/auth/ecopa-registration', [
        'ecopa_url' => 'https://ecopa.example.test',
        'registration_token' => 'one-time-registration-token',
    ])->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('data.configured', false)
        ->assertJsonPath('data.registration_status', 'pending')
        ->assertJsonPath('data.registration_request_id', 'registration-123');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://ecopa.example.test/api/app-registration-requests'
        && $request->hasHeader('X-Ecopa-Registration-Token', 'one-time-registration-token')
        && $request->data() === [
            'name' => 'Akunta',
            'slug' => 'accounting',
            'base_url' => 'https://accounting.example.test',
        ]);

    $encrypted = DB::table('ecopa_config_integration')
        ->where('name', 'registration_verification_secret')
        ->value('value');
    expect($encrypted)->not->toBe('one-time-registration-token')
        ->and(Crypt::decryptString($encrypted))->toBe('one-time-registration-token');
    $this->assertDatabaseMissing('ecopa_config_integration', ['name' => 'integration_status']);
});

it('activates encrypted runtime SSO configuration from a signed Ecopa approval callback', function () {
    $bootstrapSecret = 'one-time-registration-token';
    foreach ([
        'registration_status' => 'pending',
        'registration_request_id' => 'registration-456',
        'base_url' => 'https://accounting.example.test',
        'ecopa_url' => 'https://ecopa.example.test',
        'registration_verification_secret' => Crypt::encryptString($bootstrapSecret),
    ] as $name => $value) {
        EcopaConfigIntegration::query()->create(compact('name', 'value'));
    }

    $payload = [
        'event' => 'app.registration.approved',
        'event_id' => 'approval-event-1',
        'subject' => [
            'registration_request_id' => 'registration-456',
            'app_slug' => 'accounting',
            'client_id' => 'accounting-client',
            'client_secret' => 'client-secret-value',
            'webhook_secret' => str_repeat('w', 40),
        ],
    ];
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $json, $bootstrapSecret);

    $this->call('POST', '/webhooks/ecopa', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_ECOPA_SIGNATURE' => $signature,
    ], $json)->assertOk()
        ->assertJsonPath('code', 'registration_activated');

    expect(config('ecopa.client_id'))->toBe('accounting-client')
        ->and(config('ecopa.client_secret'))->toBe('client-secret-value')
        ->and(config('ecopa.webhook_secret'))->toBe(str_repeat('w', 40));
    $this->assertDatabaseHas('ecopa_config_integration', [
        'name' => 'integration_status',
        'value' => 'on',
    ]);
    expect(DB::table('ecopa_config_integration')->where('name', 'client_secret')->value('value'))
        ->not->toBe('client-secret-value');

    // Lost-response retries remain authenticatable with the bootstrap secret.
    $this->call('POST', '/webhooks/ecopa', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_ECOPA_SIGNATURE' => $signature,
    ], $json)->assertOk()->assertJsonPath('status', 'already_processed');
});

it('disables password login after Ecopa mode is active', function () {
    EcopaConfigIntegration::query()->create(['name' => 'integration_status', 'value' => 'on']);

    $this->postJson('/api/auth/login', [
        'email' => $this->admin->email,
        'password' => 'secret',
    ])->assertForbidden()
        ->assertJsonPath('message', 'Login lokal dinonaktifkan karena Akunta menggunakan Ecopa.');
});

it('lets only an Akunta app admin inspect safe Ecopa webhook logs', function () {
    EcopaWebhookLog::query()->create([
        'event_id' => 'visible-event-id',
        'event' => 'user.assigned',
        'subject_reference' => 'user_id:ecopa-user-1',
        'outcome' => 'processed',
        'result_code' => 'user_access_assigned',
        'http_status' => 200,
        'signature_valid' => true,
        'retryable' => false,
        'duration_ms' => 12,
        'received_at' => now(),
        'completed_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->getJson('/api/v1/spa/ecopa-integration/webhook-logs')
        ->assertOk()
        ->assertJsonPath('data.0.event_id', 'visible-event-id')
        ->assertJsonPath('data.0.outcome', 'processed')
        ->assertJsonPath('events.0.event', 'app.registration.approved')
        ->assertJsonPath('meta.retention_months', 12)
        ->assertJsonMissingPath('data.0.payload')
        ->assertJsonMissingPath('data.0.signature');

    $regularUser = User::create([
        'name' => 'Regular Ecopa User',
        'email' => 'regular-log-user@example.test',
        'password_hash' => bcrypt('secret'),
    ]);

    $this->actingAs($regularUser)
        ->withSession(['ecopa.app_role' => 'user'])
        ->getJson('/api/v1/spa/ecopa-integration/webhook-logs')
        ->assertForbidden();
});
