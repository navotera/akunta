<?php

declare(strict_types=1);

namespace App\Filament\Clusters;

use App\Filament\Clusters\Settings\Pages\Cron;
use App\Filament\Pages\Onboarding\CoaTemplate;
use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;

class Settings extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $activeNavigationIcon = 'heroicon-s-cog-6-tooth';

    protected static ?string $navigationLabel = 'Pengaturan';

    protected static ?string $title = 'Pengaturan';

    protected static ?string $clusterBreadcrumb = 'Pengaturan';

    protected static ?string $slug = 'pengaturan';

    protected static ?int $navigationSort = 99;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    public static function canAccess(): bool
    {
        return CoaTemplate::canAccess() || Cron::canAccess();
    }
}
