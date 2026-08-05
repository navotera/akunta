<?php

declare(strict_types=1);

use Akunta\EcopaClient\EcopaClient;
use Akunta\EcopaClient\Exceptions\EcopaException;

beforeEach(function () {
    config()->set('app.spa_url', 'https://spa.example.test');
    config()->set('ecopa.client_id', 'test-client');
});

it('redirects token exchange failures to a stable error page instead of restarting SSO', function () {
    $client = Mockery::mock(EcopaClient::class);
    $client->shouldReceive('verifyState')->once()->with('valid-state')->andReturnTrue();
    $client->shouldReceive('exchangeCode')->once()->with('auth-code')
        ->andThrow(new EcopaException('Token exchange failed'));

    $this->app->instance(EcopaClient::class, $client);

    $response = $this->withSession(['ecopa.state' => 'valid-state'])
        ->get('/auth/ecopa/callback?code=auth-code&state=valid-state');

    $response->assertRedirect('https://spa.example.test/login?sso_error=token_exchange');
});

it('redirects state mismatches to a stable error page instead of returning a server error', function () {
    $client = Mockery::mock(EcopaClient::class);
    $client->shouldReceive('verifyState')->once()->with('stale-state')->andReturnFalse();
    $client->shouldNotReceive('exchangeCode');

    $this->app->instance(EcopaClient::class, $client);

    $response = $this->get('/auth/ecopa/callback?code=auth-code&state=stale-state');

    $response->assertRedirect('https://spa.example.test/login?sso_error=state_mismatch');
});
