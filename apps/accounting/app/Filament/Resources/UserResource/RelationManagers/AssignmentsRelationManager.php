<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Akunta\Rbac\Models\App;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\User;
use Akunta\Rbac\Models\UserAppAssignment;
use Akunta\Rbac\Services\AssignmentService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Throwable;

class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Peran & Penugasan';

    protected static ?string $modelLabel = 'Penugasan';

    protected static ?string $pluralModelLabel = 'Penugasan';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(12)->schema([
                Forms\Components\Select::make('app_id')
                    ->label('Aplikasi')
                    ->required()
                    ->options(fn () => App::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->columnSpan(['default' => 12, 'md' => 4]),

                Forms\Components\Select::make('role_id')
                    ->label('Peran')
                    ->required()
                    ->options(fn () => Role::query()->orderBy('name')->get()->mapWithKeys(fn (Role $r) => [$r->id => $r->name.' ('.$r->code.')'])->all())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->columnSpan(['default' => 12, 'md' => 4]),

                Forms\Components\Select::make('entity_id')
                    ->label('Entity')
                    ->options(fn () => Entity::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->placeholder('Semua entitas (tenant-wide)')
                    ->helperText('Kosongkan untuk berlaku di semua entitas dalam tenant.')
                    ->columnSpan(['default' => 12, 'md' => 4]),

                Forms\Components\DateTimePicker::make('valid_from')
                    ->label('Berlaku Dari')
                    ->native(false)
                    ->seconds(false)
                    ->placeholder('Sekarang')
                    ->columnSpan(['default' => 12, 'md' => 6]),

                Forms\Components\DateTimePicker::make('valid_until')
                    ->label('Berlaku Sampai')
                    ->native(false)
                    ->seconds(false)
                    ->after('valid_from')
                    ->placeholder('Tidak terbatas')
                    ->columnSpan(['default' => 12, 'md' => 6]),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('assigned_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('role.name')
                    ->label('Peran')
                    ->description(fn (UserAppAssignment $r) => $r->role?->code)
                    ->searchable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('app.name')
                    ->label('Aplikasi')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('entity.name')
                    ->label('Entity')
                    ->placeholder('Semua entitas'),
                Tables\Columns\TextColumn::make('assigned_at')
                    ->label('Diberikan')
                    ->dateTime('d M Y H:i')
                    ->since()
                    ->tooltip(fn ($state) => $state?->format('d M Y H:i')),
                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Habis')
                    ->dateTime('d M Y')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (UserAppAssignment $r) => $r->isActive() ? 'aktif' : ($r->revoked_at ? 'dicabut' : 'tidak aktif'))
                    ->color(fn (string $state) => match ($state) {
                        'aktif' => 'success',
                        'dicabut' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('revoked_at')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Sudah dicabut')
                    ->falseLabel('Masih aktif')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('revoked_at'),
                        false: fn ($q) => $q->whereNull('revoked_at'),
                        blank: fn ($q) => $q,
                    ),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Tambah Peran')
                    ->icon('heroicon-o-user-plus')
                    ->using(function (array $data, RelationManager $livewire) {
                        /** @var User $user */
                        $user = $livewire->getOwnerRecord();
                        $app = App::findOrFail($data['app_id']);
                        $role = Role::findOrFail($data['role_id']);
                        $entity = ! empty($data['entity_id']) ? Entity::find($data['entity_id']) : null;
                        $validFrom = ! empty($data['valid_from']) ? Carbon::parse($data['valid_from']) : null;
                        $validUntil = ! empty($data['valid_until']) ? Carbon::parse($data['valid_until']) : null;

                        return app(AssignmentService::class)->assign(
                            user: $user,
                            role: $role,
                            app: $app,
                            entity: $entity,
                            assignedBy: auth()->user(),
                            validFrom: $validFrom,
                            validUntil: $validUntil,
                        );
                    })
                    ->successNotification(
                        Notification::make()->title('Peran berhasil diberikan')->success(),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->label('Cabut')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Cabut penugasan ini? Audit log akan tercatat.')
                    ->visible(fn (UserAppAssignment $r) => $r->revoked_at === null)
                    ->action(function (UserAppAssignment $r) {
                        try {
                            app(AssignmentService::class)->revoke($r, auth()->user());
                            Notification::make()->title('Peran dicabut')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Gagal mencabut peran')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }
}
