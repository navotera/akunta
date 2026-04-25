<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = -100;

    public static function getNavigationLabel(): string
    {
        // Non-breaking space — keeps Filament's NavigationItem $label initialized.
        // CSS hides any label that is exactly NBSP via `[data-label=" "]`-style match.
        return "\u{00A0}";
    }

    public function getTitle(): string
    {
        return 'Dasbor';
    }
}
