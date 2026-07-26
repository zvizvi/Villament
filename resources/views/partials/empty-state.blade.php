{{--
    The Filament-native empty state — the WHOLE region, not just a custom
    `emptyState()` view — rendered Livewire-morphable (NOT inside the
    wire:ignore island) and teleported into a slot the island shows whenever it
    has no records.

    Why native, when the heading and description are trivial to serialize:

    - It is a one-shot region. Rendered once per page, never per row, so the
      per-row cost argument that keeps cells and record actions serialized does
      not apply at all.
    - `emptyStateActions()` are real Filament actions. Native means they get the
      complete surface — modals, loading indicators, `keyBindings()`,
      `labeledFrom()`, `postToUrl()` — instead of the subset
      `ActionTriggerSerializer` can express.
    - Stock picks the heading tag from the table's own heading level
      (`$heading ? getHeadingTag(1) : getHeadingTag()`), which a Vue
      re-implementation can only guess at.
    - Freshness is NOT a reason to prefer serializing: the island's schema is
      inlined once at first paint and never refetched, so serialized text would
      be exactly as frozen as this markup is. Both update on Livewire commits
      (filters, actions) and neither on island-only fetches (search, sort,
      pagination).

    Rendered UNCONDITIONALLY, unlike stock's `@if ($hasEmptyState)`: whether the
    table is empty is decided by the island's own fetches, long after Blade has
    run, so the markup has to already exist for the island to reveal.

    The `display: contents` wrapper is REQUIRED, not cosmetic: Alpine's teleport
    clones `template.content.cloneNode(true).firstElementChild`, so an
    element-less custom view would throw and a multi-root one would lose
    everything after its first element.
--}}
@php
    $vesLivewire = $getLivewire();
    $vesTable = $vesLivewire->getTable();
    $vesCustom = $vesTable->getEmptyState();
    $vesHeadingTag = $vesTable->getHeadingTag($vesTable->getHeading() ? 1 : 0);
    $vesActions = array_filter(
        $vesTable->getEmptyStateActions(),
        fn (\Filament\Actions\Action | \Filament\Actions\ActionGroup $action): bool => $action->isVisible(),
    );
    $vesOwner = $vesLivewire->getId();
@endphp

<template x-teleport="[data-villament-empty-state-slot='empty-state'][data-villament-owner='{{ $vesOwner }}']">
    <div data-villament-empty-state-view style="display: contents">
        @if ($vesCustom)
            {{ $vesCustom }}
        @else
            {{-- Stock's markup verbatim (tables/index.blade.php:2519-2551). --}}
            <div class="fi-ta-empty-state" role="status">
                <div class="fi-ta-empty-state-content">
                    <div class="fi-ta-empty-state-icon-bg">
                        {{ \Filament\Support\generate_icon_html($vesTable->getEmptyStateIcon(), size: \Filament\Support\Enums\IconSize::Large) }}
                    </div>

                    <{{ $vesHeadingTag }} class="fi-ta-empty-state-heading">
                        {{ $vesTable->getEmptyStateHeading() }}
                    </{{ $vesHeadingTag }}>

                    @if (filled($vesDescription = $vesTable->getEmptyStateDescription()))
                        <p class="fi-ta-empty-state-description">
                            {{ $vesDescription }}
                        </p>
                    @endif

                    @if ($vesActions)
                        <div class="fi-ta-actions fi-align-center fi-wrapped">
                            @foreach ($vesActions as $vesAction)
                                {{ $vesAction }}
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</template>

{{-- The physical teleport target; the island adopts it below the table. --}}
<div wire:ignore data-villament-slots style="display: none">
    <div
        data-villament-empty-state-slot="empty-state"
        data-villament-owner="{{ $vesOwner }}"
    ></div>
</div>
