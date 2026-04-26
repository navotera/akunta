<?php

declare(strict_types=1);

namespace App\Filament\Pages\Onboarding;

use App\Actions\ApplyCoaTemplateAction;
use App\Filament\Clusters\Settings;
use App\Filament\Resources\AccountResource;
use App\Models\Account;
use App\Models\User;
use App\Services\Onboarding\CoaTemplateRegistry;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class CoaTemplate extends Page implements HasForms
{
    use InteractsWithForms;

    public const PERMISSION = 'settings.coa_template.manage';

    protected static ?string $cluster = Settings::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $activeNavigationIcon = 'heroicon-s-sparkles';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Template CoA per Industri';

    protected static ?string $navigationLabel = 'Template CoA';

    protected static string $view = 'filament.pages.onboarding.coa-template';

    public ?string $template_key = 'generic';

    /** tree | flat */
    public string $previewMode = 'tree';

    public bool $showTips = true;

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: bool}> */
    public array $previewRows = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->isSsoAdmin() || $user->hasPermission(self::PERMISSION);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->form->fill(['template_key' => $this->template_key]);
        $this->loadPreviewRows();
    }

    protected function getFormSchema(): array
    {
        $registry = app(CoaTemplateRegistry::class);
        $options = collect($registry->available())
            ->mapWithKeys(fn ($t) => [$t['key'] => $t['label'].' — '.$t['description']])
            ->all();

        return [
            Forms\Components\Select::make('template_key')
                ->label('Pilih Industri')
                ->options($options)
                ->default('generic')
                ->required()
                ->native(false)
                ->live()
                ->afterStateUpdated(function (?string $state): void {
                    $this->template_key = $state ?? 'generic';
                    $this->loadPreviewRows();
                }),
        ];
    }

    public function setPreviewMode(string $mode): void
    {
        if (in_array($mode, ['tree', 'flat'], true)) {
            $this->previewMode = $mode;
        }
    }

    public function toggleTips(): void
    {
        $this->showTips = ! $this->showTips;
    }

    public function loadPreviewRows(): void
    {
        $this->previewRows = app(CoaTemplateRegistry::class)->load($this->template_key);
    }

    public function apply(): void
    {
        $entity = Filament::getTenant();
        if ($entity === null) {
            Notification::make()->title('Pilih entitas dahulu')->warning()->send();

            return;
        }

        $state = $this->form->getState();
        $key   = $state['template_key'] ?? 'generic';

        $r = app(ApplyCoaTemplateAction::class)->execute($entity->id, $key);

        Notification::make()
            ->title("Template '{$key}' diterapkan")
            ->body("{$r['created']} akun dibuat · {$r['skipped']} dilewati (sudah ada) · {$r['total']} total dalam template.")
            ->success()
            ->send();

        $this->loadPreviewRows();
    }

    public function getExistingCount(): int
    {
        $entity = Filament::getTenant();
        if ($entity === null) {
            return 0;
        }

        return Account::where('entity_id', $entity->id)->count();
    }

    /** @return Collection<int, object> */
    public function getStubAccounts(): Collection
    {
        return collect($this->previewRows)->map(fn (array $r) => (object) [
            'id'                => $r[0],
            'code'              => $r[0],
            'name'              => $r[1],
            'type'              => $r[2],
            'normal_balance'    => $r[3],
            'parent_account_id' => $r[4],
            'is_postable'       => (bool) $r[5],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function buildSideTree(Collection $accounts, string $side): array
    {
        $subset = $accounts->where('normal_balance', $side)->values();
        $idsSet = array_flip($subset->pluck('id')->all());

        $byParent = [];
        foreach ($subset as $a) {
            $parentKey = $a->parent_account_id;
            if ($parentKey === null || ! isset($idsSet[$parentKey])) {
                $parentKey = '__root__';
            }
            $byParent[$parentKey][] = $a;
        }

        $build = function (?string $parentKey) use (&$build, &$byParent): array {
            $children = $byParent[$parentKey ?? '__root__'] ?? [];

            return array_map(function (object $a) use (&$build): array {
                return [
                    'account'  => $a,
                    'children' => $build($a->code),
                ];
            }, $children);
        };

        return $build(null);
    }

    /** @return array<string, Collection<int, object>> */
    public function groupByType(Collection $accounts): array
    {
        return $accounts
            ->groupBy('type')
            ->sortKeys()
            ->all();
    }

    public function getTypeLabel(string $type): string
    {
        return AccountResource::TYPES[$type] ?? $type;
    }

    /** @return array{total: int, postable: int, groups: int, debit: int, credit: int, by_type: array<string, int>} */
    public function getStats(): array
    {
        $rows = $this->previewRows;
        $byType = [];
        $postable = 0;
        $debit = 0;
        $credit = 0;
        foreach ($rows as $r) {
            $byType[$r[2]] = ($byType[$r[2]] ?? 0) + 1;
            if ($r[5]) {
                $postable++;
            }
            if ($r[3] === 'debit') {
                $debit++;
            } else {
                $credit++;
            }
        }

        return [
            'total'    => count($rows),
            'postable' => $postable,
            'groups'   => count($rows) - $postable,
            'debit'    => $debit,
            'credit'   => $credit,
            'by_type'  => $byType,
        ];
    }
}
