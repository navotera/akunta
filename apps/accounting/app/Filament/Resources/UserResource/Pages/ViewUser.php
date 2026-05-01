<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected static string $view = 'filament.resources.user.view-user';

    /**
     * Combine the infolist content + each RelationManager into a single tab strip,
     * rendered vertically (sidebar style) via custom blade. Avoids long-scroll
     * detail pages on small viewports.
     */
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Detail';
    }

    public function getContentTabIcon(): ?string
    {
        return 'heroicon-o-identification';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
