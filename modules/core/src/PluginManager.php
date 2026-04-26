<?php

declare(strict_types=1);

namespace Akunta\Core;

use Akunta\Core\Contracts\Plugin;
use Illuminate\Contracts\Container\Container;

/**
 * Discovers + registers Akunta plugins.
 *
 * Two registration paths:
 *   1. Manually via PluginManager::register($pluginClass)
 *   2. Auto-discover via composer.json `extra.akunta.plugins[]`
 *      — apps' service provider can scan with PluginManager::discover().
 */
class PluginManager
{
    /** @var array<string, Plugin> id => instance */
    protected array $plugins = [];

    /** @var array<string, bool> id => booted flag */
    protected array $booted = [];

    public function __construct(
        protected Container $app,
        protected HookManager $hooks,
    ) {}

    /**
     * Register a plugin class. Idempotent.
     */
    public function register(string $pluginClass): Plugin
    {
        $instance = $this->app->make($pluginClass);
        if (! $instance instanceof Plugin) {
            throw new \InvalidArgumentException(
                "{$pluginClass} must implement Akunta\\Core\\Contracts\\Plugin"
            );
        }

        $id = $instance->id();
        if (isset($this->plugins[$id])) {
            return $this->plugins[$id];
        }

        $this->plugins[$id] = $instance;
        $instance->register($this->hooks);

        return $instance;
    }

    /**
     * Boot all registered plugins respecting dependencies.
     * Call once from a Laravel ServiceProvider::boot() — typically the app's
     * AppServiceProvider after panel + Filament initialized.
     */
    public function boot(): void
    {
        foreach (array_keys($this->plugins) as $id) {
            $this->bootOne($id);
        }
    }

    protected function bootOne(string $id): void
    {
        if (! empty($this->booted[$id])) {
            return;
        }
        if (! isset($this->plugins[$id])) {
            return;
        }

        // Boot dependencies first
        foreach ($this->plugins[$id]->dependencies() as $depId) {
            $this->bootOne($depId);
        }

        $this->plugins[$id]->boot();
        $this->booted[$id] = true;
    }

    /**
     * Discover plugins from package composer.json extra.akunta.plugins.
     *
     * Each composer.json (root + dependencies) MAY declare:
     *   "extra": {
     *       "akunta": {
     *           "plugins": [
     *               "Vendor\\PluginName\\Plugin"
     *           ]
     *       }
     *   }
     */
    public function discover(string $vendorDir): void
    {
        $installedJson = $vendorDir . '/composer/installed.json';
        if (! file_exists($installedJson)) {
            return;
        }

        $installed = json_decode((string) file_get_contents($installedJson), true);
        $packages = $installed['packages'] ?? [];

        foreach ($packages as $pkg) {
            $classes = $pkg['extra']['akunta']['plugins'] ?? [];
            foreach ($classes as $class) {
                if (class_exists($class)) {
                    $this->register($class);
                }
            }
        }
    }

    /**
     * @return array<string, Plugin>
     */
    public function all(): array
    {
        return $this->plugins;
    }

    public function get(string $id): ?Plugin
    {
        return $this->plugins[$id] ?? null;
    }
}
