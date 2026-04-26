<?php

declare(strict_types=1);

namespace Akunta\Core\Contracts;

use Akunta\Core\HookManager;

/**
 * Akunta plugin contract — WordPress-style for second-tier apps.
 *
 * A plugin is any class that implements this interface AND is registered with
 * Akunta\Core\PluginManager (auto-discovered via composer or registered manually).
 *
 * Lifecycle methods are called by PluginManager:
 *   register()   — bind services, hooks; runs early during app boot.
 *   boot()       — wire UI/routes/widgets after Laravel + Filament fully booted.
 *   onInstall()  — one-shot: migrations, seed data, default settings.
 *   onUninstall()— inverse of install: drop tables, clean settings.
 */
interface Plugin
{
    /**
     * Stable identifier — used as cache key, settings prefix, audit actor.
     * Format: `vendor/plugin-slug` (lowercase, dash-separated).
     */
    public function id(): string;

    /** Human-readable name. */
    public function name(): string;

    /** Semver. */
    public function version(): string;

    /**
     * Other plugin IDs that must boot before this one (optional).
     * @return array<int, string>
     */
    public function dependencies(): array;

    /**
     * Bind services, register filter/action hooks. Runs early — DO NOT touch
     * Filament panels here; use boot() instead.
     */
    public function register(HookManager $hooks): void;

    /**
     * Wire UI: register Filament widgets/resources/pages, mount routes,
     * add menu items. Runs after Laravel + Filament fully booted.
     */
    public function boot(): void;

    /**
     * One-shot install — migrations, seed data, default settings.
     */
    public function onInstall(): void;

    /**
     * Inverse of install — drop plugin-owned tables, purge settings.
     */
    public function onUninstall(): void;
}
