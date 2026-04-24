<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExpenseResource\Pages;
use App\Models\Expense;
use App\Models\Fund;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Kas Kecil';

    protected static ?string $modelLabel = 'Expense';

    protected static ?string $pluralModelLabel = 'Expenses';

    protected static ?string $tenantOwnershipRelationshipName = 'entity';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Select::make('fund_id')
                ->label('Fund')
                ->options(fn () => Fund::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required(),
            Forms\Components\DatePicker::make('expense_date')
                ->label('Tanggal')
                ->required(),
            Forms\Components\TextInput::make('amount')
                ->label('Jumlah (IDR)')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->required(),
            Forms\Components\TextInput::make('category_code')
                ->label('Kode Kategori Biaya')
                ->required()
                ->maxLength(40)
                ->helperText('Kode akun biaya di accounting (contoh: 6103 Listrik, 6104 Perlengkapan)'),
            Forms\Components\TextInput::make('reference')
                ->maxLength(120),
            Forms\Components\Textarea::make('memo')
                ->rows(2),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('expense_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('fund.name')->label('Fund')->toggleable(),
                Tables\Columns\TextColumn::make('category_code')->label('Kategori')->badge(),
                Tables\Columns\TextColumn::make('amount')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => Expense::STATUS_DRAFT,
                        'warning' => Expense::STATUS_APPROVED,
                        'success' => Expense::STATUS_PAID,
                    ]),
                Tables\Columns\TextColumn::make('journal_id')->label('Journal ID')->toggleable()->copyable(),
                Tables\Columns\TextColumn::make('paid_at')->dateTime()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    Expense::STATUS_DRAFT => 'Draft',
                    Expense::STATUS_APPROVED => 'Approved',
                    Expense::STATUS_PAID => 'Paid',
                ]),
                Tables\Filters\SelectFilter::make('fund_id')
                    ->label('Fund')
                    ->options(fn () => Fund::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn (Expense $r) => $r->isDraft()),
            ])
            ->defaultSort('expense_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }
}
