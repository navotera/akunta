<?php

namespace Akunta\EcopaClient\Filament\Pages;

use Akunta\EcopaClient\EcopaClient;
use Akunta\EcopaClient\Exceptions\EcopaException;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Cross-app profile editor — drop-in Filament v3 Page.
 *
 * Loads current profile via Ecopa /oauth/userinfo using session access_token.
 * Submits updates to Ecopa /api/user/me. Email is read-only.
 * Successful PATCH triggers Ecopa `user.updated` webhook → other client apps mirror.
 *
 * Register in any second-tier app's panel provider:
 *   ->pages([\Akunta\EcopaClient\Filament\Pages\EcopaProfile::class])
 */
class EcopaProfile extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationLabel = 'Profil Saya';

    protected static ?string $title = 'Profil Akun';

    protected static ?string $slug = 'profile';

    protected static string $view = 'ecopa-client::filament.pages.ecopa-profile';

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?int $navigationSort = -10;

    public ?array $data = [];

    public function mount(): void
    {
        $token = session('ecopa.access_token');

        if ($token) {
            try {
                $info = app(EcopaClient::class)->fetchUserInfo($token);
                $this->data = [
                    'name'    => $info['name']  ?? '',
                    'email'   => $info['email'] ?? '',
                    'picture' => $info['picture'] ?? null,
                ];
            } catch (EcopaException) {
                $this->data = $this->fallbackFromLocalUser();
            }
        } else {
            $this->data = $this->fallbackFromLocalUser();
        }

        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Identitas')
                    ->description('Email immutable. Hubungi admin Ecopa kalau perlu ganti email.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(120),

                        TextInput::make('email')
                            ->label('Email')
                            ->disabled()
                            ->dehydrated(false),

                        FileUpload::make('picture_upload')
                            ->label('Foto Profil')
                            ->image()
                            ->maxSize(2048)
                            ->disk('local')
                            ->directory('tmp/profile')
                            ->columnSpan(2)
                            ->helperText('Max 2MB. Disinkronkan ke semua aplikasi setelah simpan.'),
                    ]),

                Section::make('Ganti Password')
                    ->description('Kosongkan kalau tidak ingin ubah password.')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('current_password')
                            ->label('Password Saat Ini')
                            ->password()
                            ->revealable(),

                        TextInput::make('new_password')
                            ->label('Password Baru')
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->different('current_password'),

                        TextInput::make('new_password_confirmation')
                            ->label('Konfirmasi Password Baru')
                            ->password()
                            ->revealable()
                            ->same('new_password'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $token = session('ecopa.access_token');

        if (! $token) {
            Notification::make()
                ->title('Sesi Ecopa kadaluarsa')
                ->body('Logout dan login ulang via Ecopa untuk update profile.')
                ->danger()
                ->send();

            return;
        }

        $attrs = array_filter([
            'name'                       => $state['name'] ?? null,
            'current_password'           => $state['current_password'] ?? null,
            'new_password'               => $state['new_password'] ?? null,
            'new_password_confirmation'  => $state['new_password_confirmation'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $files = [];
        if (! empty($state['picture_upload'])) {
            $upload = is_array($state['picture_upload']) ? array_values($state['picture_upload'])[0] : $state['picture_upload'];
            $absPath = storage_path('app/' . $upload);
            if (file_exists($absPath)) {
                $files['picture'] = fopen($absPath, 'rb');
            }
        }

        try {
            $response = app(EcopaClient::class)->updateMyProfile($token, $attrs, $files);
        } catch (EcopaException $e) {
            Notification::make()
                ->title('Gagal update profile')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        } finally {
            foreach ($files as $h) {
                if (is_resource($h)) fclose($h);
            }
        }

        Notification::make()
            ->title('Profile berhasil di-update')
            ->body('Akan tersinkronisasi ke aplikasi lain dalam beberapa detik.')
            ->success()
            ->send();

        // Reset password fields, refresh form data
        $this->form->fill([
            'name'    => $response['name']  ?? $state['name'],
            'email'   => $response['email'] ?? $state['email'],
            'picture' => $response['picture'] ?? null,
        ]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Simpan')
                ->color('primary')
                ->action('save'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! empty(config('ecopa.client_id'))
            && (bool) config('ecopa.profile_in_nav', true);
    }

    protected function fallbackFromLocalUser(): array
    {
        $u = Auth::user();

        return [
            'name'    => $u?->name  ?? '',
            'email'   => $u?->email ?? '',
            'picture' => $u?->picture ?? null,
        ];
    }
}
