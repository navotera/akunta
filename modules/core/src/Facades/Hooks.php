<?php

declare(strict_types=1);

namespace Akunta\Core\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void addFilter(string $hook, callable $listener, int $priority = 10)
 * @method static mixed apply(string $hook, mixed $value, mixed ...$args)
 * @method static void fire(string $hook, mixed ...$payload)
 */
class Hooks extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'hooks';
    }
}
