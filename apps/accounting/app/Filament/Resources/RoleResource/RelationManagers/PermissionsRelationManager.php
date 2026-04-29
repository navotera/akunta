<?php

namespace App\Filament\Resources\RoleResource\RelationManagers;

use Akunta\Rbac\Models\Permission;
use Akunta\Rbac\Models\Role;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PermissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'permissions';

    protected static ?string $title = 'Permissions';

    protected static ?string $modelLabel = 'Permission';

    protected static ?string $pluralModelLabel = 'Permissions';

    public function isReadOnly(): bool
    {
        /** @var Role $r */
        $r = $this->getOwnerRecord();

        return $r->is_preset;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('permission_id')
                ->label('Permission')
                ->required()
                ->options(fn () => Permission::query()
                    ->orderBy('app_id')
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (Permission $p) => [$p->id => "{$p->code} — ".($p->description ?: '—')])
                    ->all())
                ->searchable()
                ->preload(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->fontFamily('mono')
                    ->weight('medium')
                    ->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Deskripsi')
                    ->placeholder('—')
                    ->limit(80)
                    ->tooltip(fn ($state) => $state),
                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('app.name')
                    ->label('Aplikasi')
                    ->badge()
                    ->color('info'),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->label('Tambah Permission')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['code', 'description']),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()->label('Cabut'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
