<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalResource\RelationManagers;

use App\Models\Attachment;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Lampiran';

    protected static ?string $modelLabel = 'Lampiran';

    protected static ?string $pluralModelLabel = 'Lampiran';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\FileUpload::make('path')
                ->label('Berkas')
                ->disk(config('filesystems.default'))
                ->directory(fn () => 'attachments/'.($this->ownerRecord->entity_id ?? 'unknown'))
                ->visibility('private')
                ->preserveFilenames()
                ->maxSize(10 * 1024) // 10 MB
                ->required(),
            Forms\Components\Textarea::make('description')
                ->label('Keterangan')
                ->rows(2)
                ->maxLength(500),
        ]);
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $disk = config('filesystems.default');
        $path = $data['path'];   // FileUpload returns the saved relative path

        $size = 0;
        $mime = null;
        try {
            $size = Storage::disk($disk)->size($path);
            $mime = Storage::disk($disk)->mimeType($path);
        } catch (\Throwable) {
            // disk may not support introspection — leave defaults
        }

        return $this->ownerRecord->attachments()->create([
            'entity_id'   => $this->ownerRecord->entity_id,
            'filename'    => basename($path),
            'mime_type'   => $mime,
            'size_bytes'  => $size,
            'disk'        => $disk,
            'path'        => $path,
            'description' => $data['description'] ?? null,
            'uploaded_by' => Auth::id(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('filename')
            ->columns([
                Tables\Columns\TextColumn::make('filename')
                    ->searchable()
                    ->limit(48),
                Tables\Columns\TextColumn::make('mime_type')
                    ->label('Tipe')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('size_bytes')
                    ->label('Ukuran')
                    ->formatStateUsing(fn (Attachment $r) => $r->humanSize())
                    ->alignRight(),
                Tables\Columns\TextColumn::make('description')
                    ->limit(40)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('uploader.name')
                    ->label('Diunggah oleh')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (Attachment $record) {
                        $url = $record->url();
                        if ($url) {
                            return redirect($url);
                        }

                        return Storage::disk($record->disk)->download($record->path, $record->filename);
                    }),
                Tables\Actions\DeleteAction::make()
                    ->after(fn (Attachment $record) => Storage::disk($record->disk)->delete($record->path)),
            ]);
    }
}
