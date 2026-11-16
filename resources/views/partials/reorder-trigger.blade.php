{{--
    Filament-native reorder trigger — the stock `reorderRecords` action, with
    whatever `reorderRecordsTriggerAction()` made of it — rendered
    Livewire-morphable and teleported into a slot in the Vue toolbar, where
    stock draws it (between the two reorder-trigger render hooks).

    Its click is `wire:click="toggleTableReordering"`: the page then hides the
    island and shows the stock table, which carries its own "done" trigger.
    Reordering itself never runs in Vue.
--}}
@php
    $vrtLivewire = $getLivewire();
    $vrtOwner = $vrtLivewire->getId();
@endphp

<template x-teleport="[data-villament-reorder-slot='toolbar'][data-villament-owner='{{ $vrtOwner }}']">
    <div style="display: contents" wire:key="{{ $vrtOwner }}.table.reorder-trigger">
        {{ $vrtLivewire->getTable()->getReorderRecordsTriggerAction(isReordering: false) }}
    </div>
</template>

{{-- The physical teleport target; the island adopts it into the toolbar. --}}
<div wire:ignore data-villament-slots style="display: none">
    <div
        data-villament-reorder-slot="toolbar"
        data-villament-owner="{{ $vrtOwner }}"
        style="display: contents"
    ></div>
</div>
