{{--
    Teleport targets in the header filter row — the row directly under the
    header that stock fills with the individual column search fields, and the
    row a header-filter package puts its inputs in. One target per column named
    by `getVillamentColumnFilterSlots()`, keyed by column name.

    Villament renders NOTHING into them: this partial only opens the contract,
    and the app (or a package) teleports Filament-rendered content in, exactly
    as partials/filters.blade.php does for the filters UI:

        <template x-teleport="[data-villament-column-filter-slot='flight_date'][data-villament-owner='{{ $this->getId() }}']">
            {{ $someFilamentComponent }}
        </template>

    A slot is rendered whether or not its column is currently visible: a column
    toggled back on at runtime must find its slot waiting. `NativeSlot` on the
    Vue side moves the slot into the cell on mount and hands it back here when
    that cell unmounts, so toggling is lossless in both directions.

    Same holder rules as every other family: wire:ignore (Livewire patches the
    teleport clones through their template, never through the moved node) and
    hidden, so an un-adopted slot shows nothing.
--}}
@php
    $vcfLivewire = $getLivewire();
    $vcfOwner = $vcfLivewire->getId();
@endphp

<div wire:ignore data-villament-slots style="display: none">
    @foreach (array_keys($vcfLivewire->getVillamentColumnFilterSlots()) as $vcfColumn)
        <div
            data-villament-column-filter-slot="{{ $vcfColumn }}"
            data-villament-owner="{{ $vcfOwner }}"
        ></div>
    @endforeach
</div>
