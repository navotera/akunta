<?php

declare(strict_types=1);

namespace Akunta\Core;

use Illuminate\Contracts\Events\Dispatcher;

/**
 * WP-style filter/action dispatch on top of Laravel Events.
 *
 * Two flavors:
 *   1. Filter (apply/addFilter) — listener chain that MUTATES and returns a value.
 *      Synchronous, deterministic ordering by priority (low = earlier, WP convention).
 *   2. Action (fire) — fire-and-forget event. Thin wrapper over Laravel event dispatcher;
 *      use standard Event::listen / subscribers to register listeners.
 *
 * Use Filter when a hook transforms data.
 * Use Action (or Laravel `event()` directly) when a hook signals an occurrence.
 */
class HookManager
{
    /**
     * @var array<string, array<int, list<callable>>>
     */
    protected array $filters = [];

    public function __construct(protected ?Dispatcher $events = null)
    {
    }

    public function addFilter(string $hook, callable $listener, int $priority = 10): void
    {
        $this->filters[$hook][$priority][] = $listener;
        ksort($this->filters[$hook]);
    }

    public function apply(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->filters[$hook] ?? [] as $bucket) {
            foreach ($bucket as $listener) {
                $value = $listener($value, ...$args);
            }
        }

        return $value;
    }

    public function fire(string $hook, mixed ...$payload): void
    {
        $dispatcher = function_exists('app') ? app('events') : $this->events;

        if ($dispatcher === null) {
            return;
        }

        $dispatcher->dispatch($hook, $payload);
    }

    public function has(string $hook): bool
    {
        return ! empty($this->filters[$hook]);
    }

    /**
     * @return list<callable>
     */
    public function listeners(string $hook): array
    {
        $flat = [];
        foreach ($this->filters[$hook] ?? [] as $bucket) {
            foreach ($bucket as $listener) {
                $flat[] = $listener;
            }
        }

        return $flat;
    }

    public function reset(?string $hook = null): void
    {
        if ($hook === null) {
            $this->filters = [];

            return;
        }

        unset($this->filters[$hook]);
    }
}
