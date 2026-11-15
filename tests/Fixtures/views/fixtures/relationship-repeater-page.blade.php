<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <button type="submit" id="save-form">Save</button>
    </form>

    @if ($saved)
        <pre id="stored-notes">{{ json_encode($this->storedNotes()) }}</pre>
    @endif

    <a id="go-repeater-form" href="{{ \Zvizvi\Villament\Tests\Fixtures\Pages\RepeaterFormPage::getUrl() }}" wire:navigate>Repeater form</a>
</x-filament-panels::page>
