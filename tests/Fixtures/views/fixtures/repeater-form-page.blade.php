<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <button type="submit" id="save-form">Save</button>
    </form>

    @if ($saved !== null)
        <pre id="saved-state">{{ json_encode($saved) }}</pre>
    @endif
</x-filament-panels::page>
