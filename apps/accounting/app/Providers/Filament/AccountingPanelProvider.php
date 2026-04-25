<?php

namespace App\Providers\Filament;

use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use DutchCodingCompany\FilamentSocialite\Provider as SocialiteProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Widgets\FinancialPulseWidget;
use App\Filament\Widgets\PeriodStatusWidget;
use App\Filament\Widgets\QuickActionsWidget;
use App\Filament\Widgets\RecentJournalsWidget;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;

class AccountingPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('accounting')
            ->path('admin-accounting')
            ->brandName('Akunta')
            ->brandLogo(fn () => view('filament.brand.logo'))
            ->brandLogoHeight('1.85rem')
            ->favicon(asset('favicon.ico'))
            ->login()
            ->viteTheme('resources/css/filament/accounting/theme-metronic.css')
            ->colors([
                // Metronic Demo3 palette
                'primary' => [
                    50  => '#EFF6FF',
                    100 => '#DBEAFE',
                    200 => '#BFDBFE',
                    300 => '#93C5FD',
                    400 => '#60A5FA',
                    500 => '#1B84FF',   // brand
                    600 => '#056EE9',   // primary-active
                    700 => '#0656B5',
                    800 => '#063F82',
                    900 => '#072A55',
                    950 => '#041732',
                ],
                'success' => [
                    50  => '#F0FDF4',
                    100 => '#DFFFEA',
                    200 => '#BBF7D0',
                    300 => '#86EFAC',
                    400 => '#4ADE80',
                    500 => '#17C653',
                    600 => '#04B440',
                    700 => '#15803D',
                    800 => '#166534',
                    900 => '#14532D',
                    950 => '#052E16',
                ],
                'warning' => [
                    50  => '#FFFBEB',
                    100 => '#FFF8DD',
                    200 => '#FEF3C7',
                    300 => '#FDE68A',
                    400 => '#FACC15',
                    500 => '#F6C000',
                    600 => '#DCA200',
                    700 => '#A16207',
                    800 => '#854D0E',
                    900 => '#713F12',
                    950 => '#422006',
                ],
                'danger' => [
                    50  => '#FFEEF3',
                    100 => '#FFD6E0',
                    200 => '#FFAFC2',
                    300 => '#FF87A4',
                    400 => '#FB587F',
                    500 => '#F8285A',
                    600 => '#D81A48',
                    700 => '#A21338',
                    800 => '#710D27',
                    900 => '#4B0918',
                    950 => '#26040C',
                ],
                'info' => [
                    50  => '#F5F0FF',
                    100 => '#F1E6FF',
                    200 => '#E2CCFF',
                    300 => '#C9A6FF',
                    400 => '#9F73F0',
                    500 => '#7239EA',
                    600 => '#5014D0',
                    700 => '#3F0FA5',
                    800 => '#2D0B78',
                    900 => '#1B074E',
                    950 => '#0E0428',
                ],
                'gray' => [
                    50  => '#FAFAFB',
                    100 => '#F1F1F4',
                    200 => '#DBDFE9',
                    300 => '#C4CADA',
                    400 => '#99A1B7',
                    500 => '#78829D',
                    600 => '#4B5675',
                    700 => '#252F4A',
                    800 => '#15182E',
                    900 => '#071437',
                    950 => '#040A22',
                ],
            ])
            ->font('Inter', url: 'https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap')
            ->darkMode()
            ->maxContentWidth(MaxWidth::Full)
            ->topNavigation()
            ->breadcrumbs(true)
            ->navigationGroups([
                NavigationGroup::make('Operasional')->collapsible(false),
                NavigationGroup::make('Laporan')->collapsible(false),
                NavigationGroup::make('Master Data')->collapsible(true),
                NavigationGroup::make('Pengaturan')->collapsible(true),
            ])
            ->tenant(\Akunta\Rbac\Models\Entity::class)
            ->tenantMenuItems([])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                \App\Filament\Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                FinancialPulseWidget::class,
                PeriodStatusWidget::class,
                QuickActionsWidget::class,
                RecentJournalsWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                \App\Http\Middleware\SharedEntitySelector::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => \Illuminate\Support\Facades\Blade::render('@livewire(\'active-period-switcher\')'),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.topbar.scripts')->render() . view('filament.topbar.clock')->render(),
            )
            ->plugin(
                FilamentSocialitePlugin::make()
                    ->providers([
                        SocialiteProvider::make('google')
                            ->label('Google')
                            ->icon('heroicon-o-globe-alt')
                            ->color(Color::Red),
                    ])
                    ->userModelClass(\App\Models\User::class)
                    ->socialiteUserModelClass(\Akunta\Rbac\Models\SocialAccount::class)
                    // Gate for creating the (user × provider) link OR a brand-new user.
                    //   - Existing user → only allow if email_verified_at is set (v1 auto-link rule).
                    //   - New user (no match) → honor AUTH_SSO_AUTO_REGISTER env (default false = blocked).
                    ->registration(function (string $provider, SocialiteUserContract $oauthUser, ?Authenticatable $user) {
                        if ($user !== null) {
                            return property_exists($user, 'email_verified_at') || method_exists($user, 'getAttribute')
                                ? $user->email_verified_at !== null
                                : false;
                        }

                        return (bool) config('services.akunta_sso.auto_register', false);
                    }),
            );
    }
}
