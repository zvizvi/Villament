{{--
    A custom `Table::header()` view. Deliberately carries an Alpine directive
    and a Livewire binding: the point of teleporting this block natively rather
    than serializing its HTML is that both stay live.
--}}
<div class="fi-fixture-table-header" x-data="{ open: false }">
    <h2>Custom header</h2>

    <button type="button" x-on:click="open = ! open" wire:click="$refresh">
        Toggle
    </button>
</div>
