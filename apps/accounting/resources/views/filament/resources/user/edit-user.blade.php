<x-filament-panels::page
    @class([
        'fi-resource-edit-record-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
        'fi-resource-record-' . $record->getKey(),
        'ak-vtab-page',
    ])
>
    @capture($form)
        <x-filament-panels::form
            id="form"
            :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
            wire:submit="save"
        >
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>
    @endcapture

    @php
        $relationManagers = $this->getRelationManagers();
        $hasCombined      = $this->hasCombinedRelationManagerTabsWithContent();
    @endphp

    @if ((! $hasCombined) || (! count($relationManagers)))
        {{ $form() }}
    @endif

    @if (count($relationManagers))
        <div class="ak-vtab">
            <x-filament-panels::resources.relation-managers
                :active-locale="isset($activeLocale) ? $activeLocale : null"
                :active-manager="$this->activeRelationManager ?? ($hasCombined ? null : array_key_first($relationManagers))"
                :content-tab-label="$this->getContentTabLabel()"
                :content-tab-icon="$this->getContentTabIcon()"
                :content-tab-position="$this->getContentTabPosition()"
                :managers="$relationManagers"
                :owner-record="$record"
                :page-class="static::class"
            >
                @if ($hasCombined)
                    <x-slot name="content">
                        {{ $form() }}
                    </x-slot>
                @endif
            </x-filament-panels::resources.relation-managers>
        </div>
    @endif

    <x-filament-panels::page.unsaved-data-changes-alert />

    @push('styles')
        <style>
            /* Vertical tab layout — sidebar left, content right (≥ md) */
            @media (min-width: 768px) {
                .ak-vtab > .fi-resource-relation-managers {
                    display: grid;
                    grid-template-columns: 14rem 1fr;
                    gap: 1rem;
                    align-items: start;
                }
                .ak-vtab .fi-tabs {
                    flex-direction: column;
                    align-items: stretch;
                    border-bottom: 0;
                    border-right: 1px solid rgb(var(--ak-rule, 220 220 220 / 0.6));
                    padding: 0.5rem;
                    gap: 0.25rem;
                    background: var(--m-bg-card, white);
                    border-radius: 0.5rem;
                    box-shadow: var(--m-shadow-xs, none);
                    position: sticky;
                    top: 1rem;
                }
                .ak-vtab .fi-tabs-item {
                    width: 100%;
                    justify-content: flex-start !important;
                    padding: 0.5rem 0.75rem !important;
                    border-radius: 0.375rem !important;
                    border-bottom: 0 !important;
                    border-left: 3px solid transparent !important;
                    text-align: left;
                }
                .ak-vtab .fi-tabs-item.fi-active,
                .ak-vtab .fi-tabs-item[aria-selected="true"] {
                    background: rgb(27 132 255 / 0.08);
                    border-left-color: rgb(27 132 255) !important;
                    color: rgb(27 132 255) !important;
                }
                .dark .ak-vtab .fi-tabs-item.fi-active,
                .dark .ak-vtab .fi-tabs-item[aria-selected="true"] {
                    background: rgb(27 132 255 / 0.16);
                }
            }
        </style>
    @endpush
</x-filament-panels::page>
