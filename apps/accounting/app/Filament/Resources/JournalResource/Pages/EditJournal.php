<?php

namespace App\Filament\Resources\JournalResource\Pages;

use App\Actions\PostJournalAction;
use App\Actions\ReverseJournalAction;
use App\Filament\Resources\JournalResource;
use App\Models\Journal;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Throwable;

class EditJournal extends EditRecord
{
    protected static string $resource = JournalResource::class;

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::ScreenTwoExtraLarge;
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Journal $r */
        $r = $this->getRecord();
        $number = $r->number ?: 'Tanpa No.';

        return new HtmlString(
            '<span style="display:inline-flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">'
            .'<span>Jurnal</span>'
            .'<span style="font-family:\'JetBrains Mono\',monospace;color:#6B685F;">'.e($number).'</span>'
            .'</span>'
        );
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Journal $r */
        $r = $this->getRecord();

        $statusColor = match ($r->status) {
            Journal::STATUS_DRAFT => '#6B685F',
            Journal::STATUS_POSTED => '#0D3B2E',
            Journal::STATUS_REVERSED => '#B8654A',
            default => '#6B685F',
        };
        $statusLabel = match ($r->status) {
            Journal::STATUS_DRAFT => 'DRAFT',
            Journal::STATUS_POSTED => 'POSTED',
            Journal::STATUS_REVERSED => 'REVERSED',
            default => strtoupper($r->status),
        };

        $parts = [
            '<span style="font-family:\'JetBrains Mono\',monospace;font-size:0.65rem;letter-spacing:0.18em;padding:2px 8px;border:1px solid '.$statusColor.';color:'.$statusColor.';border-radius:2px;">'.$statusLabel.'</span>',
            '<span style="color:#6B685F;">'.Carbon::parse($r->date)->isoFormat('dddd, D MMM YYYY').'</span>',
        ];

        if ($r->posted_at) {
            $parts[] = '<span style="color:#6B685F;">Diposting '.Carbon::parse($r->posted_at)->isoFormat('D MMM YYYY · HH:mm').'</span>';
        }
        if ($r->reversed_by_journal_id) {
            $parts[] = '<span style="color:#B8654A;">Sudah dibalik</span>';
        }

        return new HtmlString(
            '<div style="display:flex;align-items:center;gap:0.9rem;flex-wrap:wrap;font-size:0.8rem;">'
            .implode('<span style="color:#C9C5BA;">·</span>', $parts)
            .'</div>'
        );
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Hydrate amount + side from existing debit/credit so the new UI works on edit
        if (! empty($data['entries']) && is_array($data['entries'])) {
            foreach ($data['entries'] as $i => $row) {
                $debit = (float) ($row['debit'] ?? 0);
                $credit = (float) ($row['credit'] ?? 0);
                $data['entries'][$i]['amount'] = $debit > 0 ? $debit : $credit;
                $data['entries'][$i]['side'] = $debit > 0 ? 'debit' : 'credit';
            }
        }

        return $data;
    }

    public function form(Form $form): Form
    {
        $form = parent::form($form);

        /** @var Journal $r */
        $r = $this->getRecord();
        if ($r->status !== Journal::STATUS_DRAFT) {
            $form->disabled();
        }

        return $form;
    }

    protected function getHeaderActions(): array
    {
        /** @var Journal $r */
        $r = $this->getRecord();
        $isDraft = $r->status === Journal::STATUS_DRAFT;
        $isPosted = $r->status === Journal::STATUS_POSTED;

        return [
            Actions\Action::make('post')
                ->label('Post')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible($isDraft)
                ->action(function () use ($r) {
                    try {
                        app(PostJournalAction::class)->execute($r, auth()->user());
                        Notification::make()->title('Journal posted.')->success()->send();
                        $this->redirect(static::getResource()::getUrl('index'));
                    } catch (Throwable $e) {
                        Notification::make()->title('Gagal post jurnal')->body($e->getMessage())->danger()->send();
                    }
                }),

            Actions\Action::make('reverse')
                ->label('Reverse')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->form([
                    Forms\Components\Textarea::make('reason')->required()->rows(2),
                ])
                ->requiresConfirmation()
                ->visible($isPosted && ! $r->reversed_by_journal_id)
                ->action(function (array $data) use ($r) {
                    try {
                        app(ReverseJournalAction::class)->execute($r, auth()->user(), $data['reason'] ?? null);
                        Notification::make()->title('Journal reversed.')->success()->send();
                        $this->redirect(static::getResource()::getUrl('index'));
                    } catch (Throwable $e) {
                        Notification::make()->title('Gagal reverse jurnal')->body($e->getMessage())->danger()->send();
                    }
                }),

            Actions\DeleteAction::make()
                ->visible($isDraft),
        ];
    }

    protected function getFormActions(): array
    {
        /** @var Journal $r */
        $r = $this->getRecord();
        if ($r->status !== Journal::STATUS_DRAFT) {
            return [];
        }

        return parent::getFormActions();
    }
}
