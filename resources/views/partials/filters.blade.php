{{--
    Filament-native filters UI, rendered Livewire-morphable (NOT inside the
    wire:ignore island) and placed per FiltersLayout. The Vue island renders
    NO filter controls of its own.

    Dialog layouts (Dropdown, Modal): the native filters CONTENT (heading,
    fields, apply, reset) is kept hidden; the Vue funnel toggles it via the
    bridge, which presents it as a floating dropdown or a centered modal (with
    a backdrop). We render the raw content and own show/hide ourselves — so
    Filament's fragile dropdown/modal Alpine (x-float, filamentModal) never
    runs. The FIELDS are the same native Livewire form that already works.

    Inline layouts (Above/Below/Before/After + collapsible): the native filters
    are teleported into the positional Vue slot — plain containers, no
    floating-ui, so Alpine teleport is safe.
--}}
@php
    use Filament\Support\Enums\Width;
    use Filament\Tables\Enums\FiltersLayout;

    $vfLivewire = $getLivewire();
    $vfTable = $vfLivewire->getTable();
    $vfLayout = $vfTable->getFiltersLayout();
    $vfTrigger = $vfTable->getFiltersTriggerAction();
    $vfForm = $vfLivewire->getTableFiltersForm();
    $vfApply = $vfTable->getFiltersApplyAction();
    $vfResetPosition = $vfTable->getFiltersResetActionPosition();
    $vfMaxHeight = $vfTable->getFiltersFormMaxHeight();

    $vfIsModal = $vfLayout === FiltersLayout::Modal;
    $vfIsAbove = in_array($vfLayout, [FiltersLayout::AboveContent, FiltersLayout::AboveContentCollapsible], true);
    $vfIsBelow = $vfLayout === FiltersLayout::BelowContent;
    $vfIsBefore = in_array($vfLayout, [FiltersLayout::BeforeContent, FiltersLayout::BeforeContentCollapsible], true);
    $vfIsAfter = in_array($vfLayout, [FiltersLayout::AfterContent, FiltersLayout::AfterContentCollapsible], true);
    $vfIsCollapsible = in_array($vfLayout, [
        FiltersLayout::AboveContentCollapsible,
        FiltersLayout::BeforeContentCollapsible,
        FiltersLayout::AfterContentCollapsible,
    ], true);
    $vfInlineSlot = match (true) {
        $vfIsAbove => 'above',
        $vfIsBelow => 'below',
        $vfIsBefore => 'before',
        $vfIsAfter => 'after',
        default => null,
    };

    // Panel/modal width — explicit filtersFormWidth() first, else derived from
    // filtersFormColumns() (stock maps ints; a responsive array is mapped by
    // its largest breakpoint, where stock falls back to ExtraSmall).
    $vfWidth = $vfTable->getFiltersFormWidth();

    if (blank($vfWidth)) {
        $vfColumns = $vfTable->getFiltersFormColumns();

        $vfWidth = match (is_array($vfColumns) ? max($vfColumns) : $vfColumns) {
            2 => Width::TwoExtraLarge,
            3 => Width::FourExtraLarge,
            4 => Width::SixExtraLarge,
            default => Width::ExtraSmall,
        };
    }

    if (is_string($vfWidth)) {
        $vfWidth = Width::tryFrom($vfWidth) ?? $vfWidth;
    }
@endphp

@if ($vfInlineSlot !== null)
    {{-- Inline layouts: teleport the native filters into the positional slot. --}}
    <template x-teleport="[data-villament-filters-slot='{{ $vfInlineSlot }}']">
        <div
            @if ($vfIsCollapsible) x-data="{ open: false }" @endif
            class="fi-ta-filters-{{ $vfInlineSlot }}-content-ctn"
        >
            @if ($vfIsCollapsible)
                <span class="fi-ta-filters-trigger-action-ctn" x-on:click="open = ! open">
                    {{ $vfTrigger->badge($vfTable->getActiveFiltersCount()) }}
                </span>

                <div x-show="open" x-collapse>
                    <div class="fi-ta-ctn" style="display: contents">
                        <x-filament-tables::filters
                            :apply-action="$vfApply"
                            :form="$vfForm"
                            :reset-action-position="$vfResetPosition"
                        />
                    </div>
                </div>
            @else
                <div class="fi-ta-ctn" style="display: contents">
                    <x-filament-tables::filters
                        :apply-action="$vfApply"
                        :form="$vfForm"
                        :reset-action-position="$vfResetPosition"
                    />
                </div>
            @endif
        </div>
    </template>
@else
    {{-- Dialog layouts (Dropdown + Modal): the native filters CONTENT, hidden;
         the Vue funnel toggles it via the bridge. WE own show/hide, presenting
         it as a floating dropdown or a centered modal per
         `data-villament-filters-presentation`. The panel sizes itself via
         Filament's own `fi-dropdown-panel` (width:100vw capped by fi-width),
         added by the bridge on open — no custom width. --}}
    <div
        data-villament-filters-panel
        data-villament-filters-presentation="{{ $vfIsModal ? 'modal' : 'dropdown' }}"
        wire:key="{{ $vfLivewire->getId() }}.villament-filters-panel"
        wire:ignore.self
        @class([
            'villament-filters-panel',
            ($vfWidth instanceof Width) ? "fi-width-{$vfWidth->value}" : $vfWidth,
            'fi-scrollable' => $vfMaxHeight,
        ])
        @style([
            'display: none',
            ('max-height: '.e($vfMaxHeight)) => $vfMaxHeight,
        ])
    >
        {{-- Reconstruct the stock ancestor chain (.fi-ta-ctn >
             .fi-ta-header-toolbar > .fi-ta-filters-dropdown) with display:
             contents wrappers so the scoped stock filter CSS applies here. --}}
        <div class="fi-ta-ctn" style="display: contents">
            <div class="fi-ta-header-toolbar" style="display: contents">
                <div class="fi-ta-filters-dropdown" style="display: contents">
                    <x-filament-tables::filters
                        :apply-action="$vfApply"
                        :form="$vfForm"
                        :reset-action-position="$vfResetPosition"
                    />
                </div>
            </div>
        </div>
    </div>
@endif
