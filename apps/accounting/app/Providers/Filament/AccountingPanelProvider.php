<?php

namespace App\Providers\Filament;

use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use DutchCodingCompany\FilamentSocialite\Provider as SocialiteProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Widgets\AccountingStatsOverview;
use App\Filament\Widgets\PeriodStatusWidget;
use App\Filament\Widgets\QuickActionsWidget;
use App\Filament\Widgets\RecentJournalsWidget;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
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
            ->login()
            ->colors([
                'primary' => Color::Indigo,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Sky,
                'gray' => Color::Slate,
            ])
            ->font('Inter')
            ->darkMode()
            ->maxContentWidth(MaxWidth::Full)
            ->sidebarCollapsibleOnDesktop()
            ->breadcrumbs(true)
            ->navigationGroups([
                NavigationGroup::make('Operasional')->icon('heroicon-o-bolt')->collapsible(false),
                NavigationGroup::make('Laporan')->icon('heroicon-o-chart-pie')->collapsible(false),
                NavigationGroup::make('Master Data')->icon('heroicon-o-rectangle-stack')->collapsible(true),
                NavigationGroup::make('Pengaturan')->icon('heroicon-o-cog-6-tooth')->collapsible(true),
            ])
            ->tenant(\Akunta\Rbac\Models\Entity::class)
            ->tenantMenuItems([])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                AccountingStatsOverview::class,
                PeriodStatusWidget::class,
                RecentJournalsWidget::class,
                QuickActionsWidget::class,
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
