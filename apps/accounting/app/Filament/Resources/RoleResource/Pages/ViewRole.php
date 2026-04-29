<?php

namespace App\Filament\Resources\RoleResource\Pages;

use Akunta\Rbac\Models\Role;
use App\Filament\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Role $r */
        $r = $this->getRecord();

        return [
            Actions\EditAction::make()->visible(! $r->is_preset),
        ];
    }
}
