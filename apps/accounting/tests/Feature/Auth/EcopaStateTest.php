<?php

use Akunta\EcopaClient\EcopaClient;
use Illuminate\Support\Facades\Session;

function makeEcopaStateClient(): EcopaClient
{
    return new EcopaClient([
        'url' => 'https://home.example.test',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://akunta.example.test/auth/ecopa/callback',
    ]);
}

it('does not consume a valid state when an invalid callback arrives first', function () {
    $client = makeEcopaStateClient();
    Session::put('ecopa.state', 'valid-state');

    expect($client->verifyState('stale-state'))->toBeFalse()
        ->and(Session::get('ecopa.state'))->toBe('valid-state')
        ->and($client->verifyState('valid-state'))->toBeTrue()
        ->and(Session::has('ecopa.state'))->toBeFalse();
});
