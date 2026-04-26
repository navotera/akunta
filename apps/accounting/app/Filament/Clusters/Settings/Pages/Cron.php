<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Models\User;
use App\Console\Commands\SchedulerHeartbeatCommand;
use App\Filament\Clusters\Settings;
use App\Models\CronRunLog;
use App\Models\CronSetting;
use App\Services\SchedulerStatus;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class Cron extends Page implements HasTable
{
    use InteractsWithTable;

    public const PERMISSION = 'settings.cron.manage';

    protected static ?string $cluster = Settings::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Cron';

    protected static ?string $title = 'Cron';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.clusters.settings.pages.cron';

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    public string $activeTab = 'status';

    /** @var array<string, mixed> */
    public array $cron = [];

    public bool $showInstructions = false;

    public int $retentionDays = 30;

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->refreshStatus();
        $this->retentionDays = CronSetting::instance()->retention_days;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->isSsoAdmin() || $user->hasPermission(self::PERMISSION);
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab === 'log' ? 'log' : 'status';
    }

    public function refreshStatus(): void
    {
        $this->cron = app(SchedulerStatus::class)->status();
    }

    public function toggleInstructions(): void
    {
        $this->showInstructions = ! $this->showInstructions;
    }

    public function testManualHeartbeat(): void
    {
        Artisan::call(SchedulerHeartbeatCommand::class);
        $this->refreshStatus();

        Notification::make()
            ->title('Manual heartbeat tertulis')
            ->body('Tunggu 60–90 detik tanpa menekan tombol ini lalu refresh. Jika status tetap "tidak sehat", cron OS belum aktif.')
            ->success()
            ->send();
    }

    public function clearHeartbeat(): void
    {
        Cache::forget(SchedulerHeartbeatCommand::CACHE_KEY);
        $this->refreshStatus();

        Notification::make()
            ->title('Heartbeat dihapus')
            ->body('Tunggu hingga ~60 detik. Jika cron OS aktif, status akan hijau kembali otomatis.')
            ->warning()
            ->send();
    }

    public function saveRetention(): void
    {
        $value = (int) $this->retentionDays;

        if ($value < CronSetting::RETENTION_MIN || $value > CronSetting::RETENTION_MAX) {
            Notification::make()
                ->title('Retensi tidak valid')
                ->body('Nilai harus antara '.CronSetting::RETENTION_MIN.' dan '.CronSetting::RETENTION_MAX.' hari.')
                ->danger()
                ->send();

            $this->retentionDays = CronSetting::instance()->retention_days;

            return;
        }

        $setting = CronSetting::instance();
        $setting->retention_days = $value;
        $setting->save();

        Notification::make()
            ->title('Retensi disimpan')
            ->body("Activity log akan disimpan {$value} hari sebelum dihapus otomatis.")
            ->success()
            ->send();
    }

    public function getCronCommandSnippet(): string
    {
        return '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runPruneNow')
                ->label('Bersihkan Sekarang')
                ->icon('heroicon-m-trash')
                ->color('gray')
                ->visible(fn () => $this->activeTab === 'log')
                ->requiresConfirmation()
                ->modalHeading('Hapus log lebih lama dari batas retensi?')
                ->modalDescription(fn () => 'Akan menghapus baris cron_run_logs yang lebih lama dari '.CronSetting::instance()->retention_days.' hari.')
                ->action(function () {
                    Artisan::call('accounting:prune-cron-logs');
                    $this->resetTable();
                    Notification::make()->title('Pruning selesai')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(CronRunLog::query()->latest('started_at'))
            ->columns([
                TextColumn::make('command')
                    ->label('Perintah')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn (CronRunLog $r) => $r->command),
                TextColumn::make('started_at')
                    ->label('Mulai')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),
                TextColumn::make('duration_ms')
                    ->label('Durasi')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state).' ms')
                    ->alignRight(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (CronRunLog $r) => match (true) {
                        $r->finished_at === null => 'berjalan',
                        $r->failed              => 'gagal',
                        default                 => 'sukses',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'sukses'   => 'success',
                        'gagal'    => 'danger',
                        'berjalan' => 'warning',
                        default    => 'gray',
                    }),
                TextColumn::make('exit_code')
                    ->label('Exit')
                    ->placeholder('—')
                    ->alignRight(),
            ])
            ->filters([
                SelectFilter::make('command')
                    ->label('Perintah')
                    ->options(fn () => CronRunLog::query()
                        ->select('command')
                        ->distinct()
                        ->orderBy('command')
                        ->pluck('command', 'command')
                        ->all()),
                Filter::make('failed_only')
                    ->label('Hanya gagal')
                    ->query(fn (Builder $q) => $q->where('failed', true))
                    ->toggle(),
            ])
            ->defaultSort('started_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->striped();
    }
}
