<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <button type="submit" id="save-form">Save</button>
    </form>

    {{-- `wire:navigate` round trips in the browser suite. --}}
    <a id="go-relationship-repeater" href="{{ \Zvizvi\Villament\Tests\Fixtures\Pages\RelationshipRepeaterPage::getUrl() }}" wire:navigate>Relationship repeater</a>

    @if ($saved !== null)
        <pre id="saved-state">{{ json_encode($saved) }}</pre>
    @endif
</x-filament-panels::page>
