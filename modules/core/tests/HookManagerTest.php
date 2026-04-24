<?php

declare(strict_types=1);

use Akunta\Core\HookManager;
use Akunta\Core\Hooks;
use Illuminate\Support\Facades\Event;

function hooks(): HookManager
{
    return app('hooks');
}

it('returns original value when no filter registered', function () {
    expect(hooks()->apply('unknown.filter', 'original'))->toBe('original');
});

it('has() reflects registered filters', function () {
    expect(hooks()->has('test.filter'))->toBeFalse();
    hooks()->addFilter('test.filter', fn ($v) => $v);
    expect(hooks()->has('test.filter'))->toBeTrue();
});

it('applies a single filter', function () {
    hooks()->addFilter('test.upper', fn (string $v) => strtoupper($v));

    expect(hooks()->apply('test.upper', 'hello'))->toBe('HELLO');
});

it('chains multiple filters in registration order at same priority', function () {
    hooks()->addFilter('test.chain', fn (string $v) => $v . '-a');
    hooks()->addFilter('test.chain', fn (string $v) => $v . '-b');
    hooks()->addFilter('test.chain', fn (string $v) => $v . '-c');

    expect(hooks()->apply('test.chain', 'x'))->toBe('x-a-b-c');
});

it('runs lower-priority filters earlier (WP convention)', function () {
    hooks()->addFilter('test.prio', fn (string $v) => $v . '-10', 10);
    hooks()->addFilter('test.prio', fn (string $v) => $v . '-5',  5);
    hooks()->addFilter('test.prio', fn (string $v) => $v . '-20', 20);

    expect(hooks()->apply('test.prio', 'x'))->toBe('x-5-10-20');
});

it('passes extra args to the filter after the value', function () {
    hooks()->addFilter('test.args', function (string $v, int $times, string $suffix) {
        return str_repeat($v, $times) . $suffix;
    });

    expect(hooks()->apply('test.args', 'a', 3, '!'))->toBe('aaa!');
});

it('listeners() returns registered callbacks flattened by priority', function () {
    $a = fn () => null;
    $b = fn () => null;
    hooks()->addFilter('test.list', $a, 5);
    hooks()->addFilter('test.list', $b, 20);

    expect(hooks()->listeners('test.list'))->toBe([$a, $b]);
});

it('reset() clears a single hook', function () {
    hooks()->addFilter('test.reset', fn ($v) => $v);
    hooks()->reset('test.reset');

    expect(hooks()->has('test.reset'))->toBeFalse();
});

it('reset() with null clears everything', function () {
    hooks()->addFilter('a', fn ($v) => $v);
    hooks()->addFilter('b', fn ($v) => $v);
    hooks()->reset();

    expect(hooks()->has('a'))->toBeFalse()
        ->and(hooks()->has('b'))->toBeFalse();
});

it('fire() dispatches a Laravel event with the hook name', function () {
    Event::fake();

    hooks()->fire(Hooks::JOURNAL_AFTER_POST, ['journal' => 'jnl_1', 'user' => 'usr_1']);

    Event::assertDispatched(Hooks::JOURNAL_AFTER_POST);
});
