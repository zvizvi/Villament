{{--
    A custom `Table::emptyState()` view. Carries an Alpine directive and a
    Livewire binding on purpose: teleporting the block natively rather than
    serializing its HTML is what keeps both live.
--}}
<div class="fi-fixture-empty-state" x-data="{ shown: true }">
    <p>Nothing here yet</p>

    <button type="button" wire:click="$refresh">Reload</button>
</div>
