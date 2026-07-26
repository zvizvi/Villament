{{--
    Filament-native column manager — trigger, dropdown/modal and the whole
    panel body — rendered Livewire-morphable (NOT inside the wire:ignore
    island) and teleported into a physical slot in the Vue toolbar. Same
    mechanism, and same reasoning, as the filters partial next door: the
    manager is a one-shot control with no per-interaction performance budget,
    so re-implementing it in Vue bought nothing and cost every option stock
    supports (Modal layout, reset-in-footer, `columnManagerColumns()`, a
    customized trigger action, its modal config, `x-sortable` reordering).

    The Alpine component it needs (`filamentTableColumnManager`) ships in
    `filament/tables`'s plain `tables` JS asset, which every panel page loads —
    no `x-load` gate to satisfy.

    State stays coherent because the manager writes through Livewire:
    `applyTableColumnManager` / `resetTableColumnManager` commits are what the
    island's bridge already watches, so it re-reads `tableColumns` and refetches
    the records right after.
--}}
@php
    use Filament\Tables\Enums\ColumnManagerLayout;

    $vcmLivewire = $getLivewire();
    $vcmTable = $vcmLivewire->getTable();
    $vcmTrigger = $vcmTable->getColumnManagerTriggerAction();
    $vcmApply = $vcmTable->getColumnManagerApplyAction();
    $vcmColumns = $vcmTable->getColumnManagerColumns();
    $vcmMaxHeight = $vcmTable->getColumnManagerMaxHeight();
    $vcmWidth = $vcmTable->getColumnManagerWidth();
    $vcmIsModal = ($vcmTable->getColumnManagerLayout() === ColumnManagerLayout::Modal) || $vcmTrigger->isModalSlideOver();
    $vcmOwner = $vcmLivewire->getId();
@endphp

<template x-teleport="[data-villament-columns-slot='toolbar'][data-villament-owner='{{ $vcmOwner }}']">
    @if ($vcmIsModal)
        {{-- Stock's Modal branch: the panel body only, inside the trigger
             action's own modal (heading, width, slide-over, footer actions and
             content hooks all come from the action). --}}
        <x-filament::modal
            :alignment="$vcmTrigger->getModalAlignment()"
            :autofocus="$vcmTrigger->isModalAutofocused()"
            :close-button="$vcmTrigger->hasModalCloseButton()"
            :close-by-clicking-away="$vcmTrigger->isModalClosedByClickingAway()"
            :close-by-escaping="$vcmTrigger->isModalClosedByEscaping()"
            :description="$vcmTrigger->getModalDescription()"
            :extra-modal-window-attribute-bag="$vcmTrigger->getExtraModalWindowAttributeBag()"
            :extra-modal-overlay-attribute-bag="$vcmTrigger->getExtraModalOverlayAttributeBag()"
            :footer-actions="$vcmTrigger->getVisibleModalFooterActions()"
            :footer-actions-alignment="$vcmTrigger->getModalFooterActionsAlignment()"
            :heading="$vcmTrigger->getCustomModalHeading() ?? __('filament-tables::table.column_manager.heading')"
            :icon="$vcmTrigger->getModalIcon()"
            :icon-color="$vcmTrigger->getModalIconColor()"
            :slide-over="$vcmTrigger->isModalSlideOver()"
            :slide-over-position="$vcmTrigger->getModalSlideOverPosition()"
            :sticky-footer="$vcmTrigger->isModalFooterSticky()"
            :sticky-header="$vcmTrigger->isModalHeaderSticky()"
            :width="$vcmWidth"
            :wire:key="$vcmOwner . '.table.column-manager'"
            class="fi-ta-col-manager-modal"
        >
            <x-slot name="trigger">
                {{ $vcmTrigger }}
            </x-slot>

            {{ $vcmTrigger->getModalContent() }}

            <div
                x-data="filamentTableColumnManager({
                            columns: $wire.entangle('tableColumns'),
                            isLive: {{ $vcmApply->isVisible() ? 'false' : 'true' }},
                        })"
                x-on:apply-table-column-manager.window="applyTableColumnManager()"
                x-on:reset-table-column-manager.window="resetDeferredColumns()"
                class="fi-ta-col-manager"
            >
                <x-filament-tables::column-manager.content
                    :columns="$vcmColumns"
                    :has-reorderable-columns="$vcmTable->hasReorderableColumns()"
                    :has-toggleable-columns="$vcmTable->hasToggleableColumns()"
                    :reorder-animation-duration="$vcmTable->getReorderAnimationDuration()"
                />
            </div>

            {{ $vcmTrigger->getModalContentFooter() }}
        </x-filament::modal>
    @else
        <x-filament::dropdown
            :max-height="$vcmMaxHeight"
            placement="bottom-end"
            shift
            :flip="false"
            :width="$vcmWidth"
            :wire:key="$vcmOwner . '.table.column-manager'"
            class="fi-ta-col-manager-dropdown"
        >
            <x-slot name="trigger">
                {{ $vcmTrigger }}
            </x-slot>

            <x-filament-tables::column-manager
                :apply-action="$vcmApply"
                :columns="$vcmColumns"
                :reset-action-position="$vcmTable->getColumnManagerResetActionPosition()"
                :has-reorderable-columns="$vcmTable->hasReorderableColumns()"
                :has-toggleable-columns="$vcmTable->hasToggleableColumns()"
                :reorder-animation-duration="$vcmTable->getReorderAnimationDuration()"
            />
        </x-filament::dropdown>
    @endif
</template>

{{-- The physical teleport target; the island adopts it into the toolbar. --}}
<div wire:ignore data-villament-slots style="display: none">
    <div
        data-villament-columns-slot="toolbar"
        data-villament-owner="{{ $vcmOwner }}"
    ></div>
</div>
