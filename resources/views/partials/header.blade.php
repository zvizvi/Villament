{{--
    Filament-native custom table header (`Table::header(view(...))`) — rendered
    Livewire-morphable (NOT inside the wire:ignore island) and teleported into a
    physical slot at the top of the Vue header container.

    Same mechanism, and same reasoning, as the filters and column-manager
    partials next door. The difference is that here we do not even KNOW what the
    markup is: `header()` takes an arbitrary View/Htmlable from the developer,
    which may carry its own Livewire bindings, Alpine directives, nested
    components or `@livewire` calls. Serializing its HTML into Vue would render
    every one of those inert. It is also a one-shot region — rendered once per
    page, never per row — so going native costs nothing in the data path.

    Stock renders this INSTEAD of the heading/description/actions block
    (`@if ($header) ... @elseif ($heading || $description || $headerActions)`),
    which is why `schema.header.hasCustomView` makes the island render only
    this slot inside `.fi-ta-header-ctn`.
--}}
@php
    $vhLivewire = $getLivewire();
    $vhHeader = $vhLivewire->getTable()->getHeader();
    $vhOwner = $vhLivewire->getId();
@endphp

<template x-teleport="[data-villament-header-slot='header'][data-villament-owner='{{ $vhOwner }}']">
    {{ $vhHeader }}
</template>

{{-- The physical teleport target; the island adopts it into the header container. --}}
<div wire:ignore data-villament-slots style="display: none">
    <div
        data-villament-header-slot="header"
        data-villament-owner="{{ $vhOwner }}"
    ></div>
</div>
