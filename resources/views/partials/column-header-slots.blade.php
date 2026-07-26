{{--
    Teleport targets inside the column header cells — one per column, keyed by
    column name. Villament renders NOTHING into them: this partial only opens
    the contract, and the app (or a package) teleports Filament-rendered
    content in, exactly as partials/filters.blade.php does for the filters UI:

        <template x-teleport="[data-villament-column-header-slot='flight_date'][data-villament-owner='{{ $this->getId() }}']">
            {{ $someFilamentComponent }}
        </template>

    Header filter forms are what this exists for — the Vue island rebuilds the
    header row, so a package that renders inputs into the stock `<th>` has
    nowhere to land otherwise.

    Rendered for EVERY column, not just the currently visible ones: a column
    toggled back on at runtime must find its slot waiting. `NativeSlot` on the
    Vue side moves the slot into the header cell on mount and hands it back
    here when that cell unmounts, so toggling is lossless in both directions.

    Same holder rules as every other family: wire:ignore (Livewire patches the
    teleport clones through their template, never through the moved node) and
    hidden, so an un-adopted slot shows nothing.
--}}
@php
    $vchLivewire = $getLivewire();
    $vchOwner = $vchLivewire->getId();
@endphp

<div wire:ignore data-villament-slots style="display: none">
    @foreach ($vchLivewire->getTable()->getColumns() as $vchColumn)
        <div
            data-villament-column-header-slot="{{ $vchColumn->getName() }}"
            data-villament-owner="{{ $vchOwner }}"
        ></div>
    @endforeach
</div>
