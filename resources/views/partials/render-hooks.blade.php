{{--
    The table header/toolbar render hooks (`Support\ToolbarRenderHooks`),
    rendered Livewire-morphable — what hangs on one may be a nested Livewire
    component, a campaign picker, say — and teleported into the Vue outlet at
    the same place stock renders them. Same mechanism as the custom header
    partial: one guaranteed root element per teleport (`display: contents`),
    and a physical slot in a hidden holder that the island adopts.
--}}
@php
    $vrLivewire = $getLivewire();
    $vrOwner = $vrLivewire->getId();
@endphp

@foreach ($renderHooks as $vrKey)
    <template x-teleport="[data-villament-hook-slot='{{ $vrKey }}'][data-villament-owner='{{ $vrOwner }}']">
        <div data-villament-render-hook="{{ $vrKey }}" style="display: contents">
            {{ \Filament\Support\Facades\FilamentView::renderHook(
                \Zvizvi\Villament\Support\ToolbarRenderHooks::HOOKS[$vrKey],
                scopes: \Zvizvi\Villament\Support\ToolbarRenderHooks::scopesFor($vrKey, $vrLivewire::class),
            ) }}
        </div>
    </template>
@endforeach

<div wire:ignore data-villament-slots style="display: none">
    @foreach ($renderHooks as $vrKey)
        <div
            data-villament-hook-slot="{{ $vrKey }}"
            data-villament-owner="{{ $vrOwner }}"
            style="display: contents"
        ></div>
    @endforeach
</div>
