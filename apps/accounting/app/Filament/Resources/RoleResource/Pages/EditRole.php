<?php

namespace App\Filament\Resources\RoleResource\Pages;

use Akunta\Rbac\Models\Role;
use App\Filament\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    public function mount($record): void
    {
        parent::mount($record);

        /** @var Role $r */
        $r = $this->getRecord();
        if ($r->is_preset) {
            abort(403, 'Preset roles tidak bisa diedit.');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
