# Plugins — WordPress-style for Akunta Second-Tier Apps

Spec resmi untuk membangun **plugin** + **widget di plugin** untuk aplikasi second-tier (Akunta Accounting, Payroll, Cash Mgmt). Mirip WordPress: drop-in, hookable, self-contained — tapi lebih terstruktur (PHP classes, dependency injection, contract-based).

> Status: **v1 spec — locked 2026-04-26**. Breaking changes butuh major bump pada `akunta/core`.

---

## 1. Filosofi

| Property | WP plugin | Akunta plugin |
|---|---|---|
| Drop-in | Folder di `wp-content/plugins/` | Composer package atau folder lokal `plugins/` |
| Hooks | `add_filter` / `add_action` | `HookManager::addFilter` / `Event::listen` |
| Discovery | File scan + activate flag | `composer.json extra.akunta.plugins[]` atau manual register |
| Lifecycle | Activate/Deactivate/Uninstall | `register()` → `boot()` → `onInstall()` / `onUninstall()` |
| Widget | `register_sidebar` + `WP_Widget` | Filament v4 Widget classes |
| Admin menu | `add_menu_page` | Filament Resource/Page navigation |
| Settings | `register_setting` | Plugin namespace via `Setting::set('plugin.{id}.{key}', ...)` |

**Prinsip:**
1. Plugin jangan menyentuh **inti** kode app — extend lewat hooks, tidak fork.
2. Setiap plugin punya **id stabil** (`vendor/plugin-slug`) — dipakai sebagai cache key, settings prefix, audit actor.
3. Plugin **boleh** ship migrations, routes, widgets, resources, translations, assets.
4. Plugin **tidak boleh** breaking-change schema milik app inti — bikin tabel sendiri.

---

## 2. Anatomi Plugin

Struktur minimum sebuah plugin sebagai Composer package:

```
my-plugin/
├── composer.json                        # autoload + extra.akunta.plugins
├── src/
│   ├── Plugin.php                        # implements Akunta\Core\Contracts\Plugin
│   ├── Hooks/                            # event listeners + filter listeners
│   ├── Filament/
│   │   ├── Widgets/                      # Filament widget classes
│   │   ├── Resources/                    # Filament Resource classes
│   │   └── Pages/                        # Custom Filament pages
│   ├── Routes/web.php                    # Plugin's HTTP routes
│   └── Migrations/                       # Plugin's DB schema
├── resources/
│   ├── views/                            # Blade templates
│   └── lang/                             # Translations
└── README.md
```

### `composer.json` minimal

```json
{
    "name": "my-vendor/akunta-plugin-foo",
    "type": "library",
    "require": {
        "php": "^8.3",
        "akunta/core": "*"
    },
    "autoload": {
        "psr-4": {
            "MyVendor\\PluginFoo\\": "src/"
        }
    },
    "extra": {
        "akunta": {
            "plugins": [
                "MyVendor\\PluginFoo\\Plugin"
            ]
        }
    }
}
```

`extra.akunta.plugins` di-pickup oleh `Akunta\Core\PluginManager::discover()`.

---

## 3. Plugin Contract

`Akunta\Core\Contracts\Plugin` — interface 7 method:

```php
interface Plugin
{
    public function id(): string;          // 'vendor/plugin-slug'
    public function name(): string;        // 'Foo Reporting'
    public function version(): string;     // '1.0.0' (semver)
    public function dependencies(): array; // ['vendor/another-plugin'] — optional

    public function register(HookManager $hooks): void;  // bind services + hooks (early)
    public function boot(): void;                         // wire UI/routes (late)

    public function onInstall(): void;     // migrations, seed, settings
    public function onUninstall(): void;   // drop tables, purge settings
}
```

### Lifecycle order

```
Laravel boot
  └─ CoreServiceProvider::register      → bind PluginManager singleton
  └─ App AppServiceProvider::register   → discover() + register all plugins
                                          → each plugin.register() runs (hooks bound)
Laravel boot complete
  └─ App AppServiceProvider::boot       → PluginManager::boot()
                                          → each plugin.boot() runs (UI wired)
                                          → dependencies auto-resolved (topological)
```

`onInstall` / `onUninstall` triggered by **artisan command** atau plugin admin page — bukan otomatis on every boot.

---

## 4. Quickstart — "Hello Plugin"

### 4.1 Folder
Buat folder lokal di `apps/accounting/plugins/hello/`:
```
plugins/hello/
├── composer.json
└── src/Plugin.php
```

### 4.2 `plugins/hello/composer.json`
```json
{
    "name": "akunta/plugin-hello",
    "type": "library",
    "autoload": {
        "psr-4": { "Akunta\\PluginHello\\": "src/" }
    },
    "extra": {
        "akunta": {
            "plugins": [ "Akunta\\PluginHello\\Plugin" ]
        }
    }
}
```

### 4.3 `plugins/hello/src/Plugin.php`
```php
<?php

namespace Akunta\PluginHello;

use Akunta\Core\Contracts\Plugin as PluginContract;
use Akunta\Core\HookManager;
use Filament\Facades\Filament;
use Akunta\PluginHello\Filament\Widgets\HelloWidget;

class Plugin implements PluginContract
{
    public function id(): string          { return 'akunta/plugin-hello'; }
    public function name(): string        { return 'Hello Plugin'; }
    public function version(): string     { return '0.1.0'; }
    public function dependencies(): array { return []; }

    public function register(HookManager $hooks): void
    {
        // Listen ke filter dari core — modify journal memo before save
        $hooks->addFilter('journal.memo', function (?string $memo): string {
            return ($memo ?? '') . ' [via hello-plugin]';
        });
    }

    public function boot(): void
    {
        // Inject widget ke Filament panel 'accounting'
        Filament::getPanel('accounting')->widgets([HelloWidget::class]);
    }

    public function onInstall(): void   {}
    public function onUninstall(): void {}
}
```

### 4.4 Register di app `composer.json`
Tambah path repo:
```json
"repositories": [
    { "type": "path", "url": "plugins/hello", "options": { "symlink": true } }
],
"require": {
    "akunta/plugin-hello": "@dev"
}
```
Jalankan:
```bash
composer update akunta/plugin-hello
```

### 4.5 Discover di `AppServiceProvider::boot`
```php
use Akunta\Core\PluginManager;

public function boot(PluginManager $plugins): void
{
    $plugins->discover(base_path('vendor'));
    $plugins->boot();
}
```

Plugin sekarang aktif. Reload `/admin-accounting` → widget muncul + filter `journal.memo` jalan.

---

## 5. Membangun Widget di Plugin

Widget = Filament v4 Widget class. Simpan di `plugins/hello/src/Filament/Widgets/HelloWidget.php`:

```php
<?php

namespace Akunta\PluginHello\Filament\Widgets;

use Filament\Widgets\Widget;

class HelloWidget extends Widget
{
    protected static string $view = 'akunta-plugin-hello::widgets.hello';

    protected int|string|array $columnSpan = 'full';

    public function getViewData(): array
    {
        return [
            'message' => 'Hello dari plugin!',
            'count'   => \App\Models\Journal::count(),
        ];
    }
}
```

View di `plugins/hello/resources/views/widgets/hello.blade.php`:

```blade
<x-filament-widgets::widget>
    <x-filament::section>
        <h2>{{ $message }}</h2>
        <p>Total jurnal: {{ $count }}</p>
    </x-filament::section>
</x-filament-widgets::widget>
```

Register namespace view di `Plugin::boot()`:
```php
public function boot(): void
{
    \Illuminate\Support\Facades\View::addNamespace(
        'akunta-plugin-hello',
        __DIR__ . '/../resources/views'
    );

    Filament::getPanel('accounting')->widgets([HelloWidget::class]);
}
```

---

## 6. Hooks — Extension Points

### 6.1 Filter (mutating value)
```php
// In core code (di app inti):
$memo = app('hooks')->apply('journal.memo', $journal->memo, $journal);

// In plugin:
$hooks->addFilter('journal.memo', fn ($memo, $journal) => "[{$journal->number}] {$memo}");
```

### 6.2 Action (fire-and-forget)
```php
// In core code:
event('journal.posted', [$journal, $user]);

// In plugin:
\Event::listen('journal.posted', function ($journal, $user) {
    // send notification, sync to external, etc.
});
```

### 6.3 Naming convention
Format: `{resource}.{lifecycle}` atau `{resource}.{event}`.
- `journal.before_post` · `journal.after_post`
- `account.before_create` · `account.after_create`
- `report.balance_sheet.compute` (filter — modify result)

Plugin **boleh** broadcast event sendiri:
```php
event('akunta.plugin-hello.something_happened', [$payload]);
```
Pakai prefix plugin id supaya tidak collision.

---

## 7. Migrations

Plugin punya tabel sendiri? Simpan di `src/Migrations/` dengan timestamp:

```
plugins/hello/src/Migrations/2026_05_01_000000_create_hello_records_table.php
```

Daftarkan saat `boot()`:
```php
public function boot(): void
{
    $this->loadMigrations();
}

protected function loadMigrations(): void
{
    \Illuminate\Support\Facades\Artisan::registerMigrationPath(
        __DIR__ . '/Migrations'
    );
}
```

Atau pakai `onInstall()` untuk on-demand (tidak otomatis tiap boot):

```php
public function onInstall(): void
{
    \Illuminate\Support\Facades\Schema::create('hello_records', function ($table) {
        $table->id();
        $table->string('message');
        $table->timestamps();
    });
}

public function onUninstall(): void
{
    \Illuminate\Support\Facades\Schema::dropIfExists('hello_records');
}
```

---

## 8. Routes

Plugin route file:
```
plugins/hello/src/Routes/web.php
```

Load di `boot()`:
```php
public function boot(): void
{
    \Illuminate\Support\Facades\Route::middleware('web')->group(
        __DIR__ . '/Routes/web.php'
    );
}
```

Plugin **disarankan** prefix route dengan plugin id:
```php
// plugins/hello/src/Routes/web.php
Route::prefix('plugins/hello')->group(function () {
    Route::get('/', fn () => view('akunta-plugin-hello::index'));
});
```

---

## 9. Filament Resources

Untuk plugin yang kontribusi resource baru (CRUD UI):

```php
// plugins/hello/src/Filament/Resources/HelloRecordResource.php
namespace Akunta\PluginHello\Filament\Resources;

use Filament\Resources\Resource;
use Akunta\PluginHello\Models\HelloRecord;

class HelloRecordResource extends Resource
{
    protected static ?string $model = HelloRecord::class;
    protected static ?string $navigationGroup = 'Plugin: Hello';
    // ... form/table/pages
}
```

Register di `Plugin::boot()`:
```php
Filament::getPanel('accounting')->resources([HelloRecordResource::class]);
```

---

## 10. Settings (per-plugin scope)

Plugin pakai `Setting::set/get` dengan prefix `plugin.{id}.{key}`:

```php
\App\Models\Setting::set('plugin.akunta/plugin-hello.greeting', 'Halo dunia');
$greeting = \App\Models\Setting::get('plugin.akunta/plugin-hello.greeting', 'Hello');
```

Helper di base abstract plugin (optional):

```php
abstract class BasePlugin implements Plugin
{
    public function setting(string $key, mixed $default = null): mixed
    {
        return \App\Models\Setting::get("plugin.{$this->id()}.{$key}", $default);
    }

    public function setSetting(string $key, mixed $value): void
    {
        \App\Models\Setting::set("plugin.{$this->id()}.{$key}", $value);
    }
}
```

---

## 11. Translations & Assets

Translations:
```
plugins/hello/resources/lang/{en,id}/messages.php
```

Load di `boot()`:
```php
\Illuminate\Support\Facades\Lang::addNamespace(
    'akunta-plugin-hello',
    __DIR__ . '/../resources/lang'
);
```
Pakai: `__('akunta-plugin-hello::messages.greeting')`.

Assets (CSS/JS): publish via Filament asset manager atau letakkan di `public/plugins/{id}/`.

---

## 12. Discovery Modes

### Mode A — Composer auto-discover (recommended)
Plugin distributed sebagai package. App's `AppServiceProvider::boot`:
```php
public function boot(PluginManager $plugins): void
{
    $plugins->discover(base_path('vendor'));
    $plugins->boot();
}
```
Setiap composer package yang declare `extra.akunta.plugins[]` otomatis ke-pickup.

### Mode B — Manual register
Buat list kecil plugin yang aktif:
```php
public function boot(PluginManager $plugins): void
{
    $plugins->register(\Akunta\PluginHello\Plugin::class);
    $plugins->register(\Akunta\PluginInvoice\Plugin::class);
    $plugins->boot();
}
```

### Mode C — Filesystem scan (future)
`plugins/` directory di-scan otomatis. Belum implemented; saat ini pakai composer path repo.

---

## 13. Plugin Admin Page (UI on/off)

Future: `Akunta\Core\Filament\PluginsResource` — CRUD on/off + install/uninstall. Saat ini plugin selalu aktif kalau registered.

Pattern manual sementara:
```php
// In Plugin::register()
if (! Setting::get('plugin.akunta/plugin-hello.enabled', '1')) {
    return;  // skip registration
}
```

---

## 14. Dependencies

Plugin yang butuh plugin lain boot duluan:

```php
public function dependencies(): array
{
    return ['akunta/plugin-core-reports'];
}
```

`PluginManager::bootOne()` resolve dependencies topologically. Cycles tidak detected — hindari self-loop.

---

## 15. Security Notes

- Plugin **MUST NOT** subvert RBAC. Cek permission via `Akunta\Rbac\...` sebelum eksekusi action sensitif.
- Plugin **SHOULD** tulis audit entry untuk action mutasi penting:
  ```php
  app('audit')->record("plugin.{$this->id()}.action_x", $target, $meta);
  ```
- Plugin **MUST NOT** bypass policy gates.
- Plugin **MUST** declare data table-nya sendiri — jangan ALTER tabel inti.

---

## 16. Testing Plugin

Pakai Pest:

```php
// plugins/hello/tests/PluginTest.php
use Akunta\PluginHello\Plugin;

it('registers filter on journal.memo', function () {
    $hooks = app('hooks');
    $plugin = new Plugin();
    $plugin->register($hooks);

    $result = $hooks->apply('journal.memo', 'Original');
    expect($result)->toBe('Original [via hello-plugin]');
});
```

---

## 17. Reference Resources

- Hook manager source: `modules/core/src/HookManager.php`
- Plugin contract: `modules/core/src/Contracts/Plugin.php`
- Plugin manager: `modules/core/src/PluginManager.php`
- Hook constants list: `modules/core/src/Hooks.php`
- Filament v4 widget docs: https://filamentphp.com/docs/4.x/widgets

---

## 18. Versioning

Plugin Contract = part of `akunta/core`. Breaking change pada `Plugin` interface = bump major version core. Tambah method baru → optional default di abstract `BasePlugin` supaya tidak breaking.

Plugin sendiri ikut semver:
- `MAJOR` — breaking hook contract change
- `MINOR` — new hooks, new widgets
- `PATCH` — bug fixes

---

## 19. Roadmap Plugin Subsystem

Ditunda untuk v2 plugin subsystem (Akunta v1.5+):
- Filesystem auto-scan dari `plugins/` directory
- Filament UI plugin manager (list, enable/disable, install/uninstall)
- Plugin marketplace
- Sandboxed plugin (vendor isolation)
- Plugin secrets/credentials encrypted-at-rest
- WebAssembly plugin runner (longer-term)

Sekarang status = **v1 stable contract**. Cukup untuk plugin internal + plugin pihak-ketiga yang trusted.
