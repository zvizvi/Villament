{{--
    The root is what Livewire keeps across `wire:navigate` (`x-persist`). On a
    navigate request it arrives empty and is replaced by the browser's own
    copy; `data-villament-shell-stub` marks one that was not (no copy under
    this key), which the island bundle then asks for a full render.
--}}
<div
    x-persist="{{ $persistKey }}"
    @if ($isStub) data-villament-shell-stub @endif
>
    @unless ($isStub)
        @include('filament-panels::livewire.sidebar')
    @endunless
</div>
