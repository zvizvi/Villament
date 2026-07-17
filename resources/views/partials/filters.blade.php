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
    {{-- Dialog layouts (Dropdown + Modal): the STOCK trigger + dialog,
         teleported into the Vue toolbar slot. --}}
    <template x-teleport="[data-villament-filters-slot='toolbar']">
        @if ($vfIsModal || $vfTrigger->isModalSlideOver())
            <x-filament::modal
                :alignment="$vfTrigger->getModalAlignment()"
                :autofocus="$vfTrigger->isModalAutofocused()"
                :close-button="$vfTrigger->hasModalCloseButton()"
                :close-by-clicking-away="$vfTrigger->isModalClosedByClickingAway()"
                :close-by-escaping="$vfTrigger->isModalClosedByEscaping()"
                :description="$vfTrigger->getModalDescription()"
                :footer-actions="$vfTrigger->getVisibleModalFooterActions()"
                :footer-actions-alignment="$vfTrigger->getModalFooterActionsAlignment()"
                :heading="$vfTrigger->getCustomModalHeading() ?? __('filament-tables::table.filters.heading')"
                :icon="$vfTrigger->getModalIcon()"
                :icon-color="$vfTrigger->getModalIconColor()"
                :slide-over="$vfTrigger->isModalSlideOver()"
                :slide-over-position="$vfTrigger->getModalSlideOverPosition()"
                :sticky-footer="$vfTrigger->isModalFooterSticky()"
                :sticky-header="$vfTrigger->isModalHeaderSticky()"
                :width="$vfWidth"
                :wire:key="$vfLivewire->getId() . '.table.filters'"
                class="fi-ta-filters-modal"
            >
                <x-slot name="trigger">
                    {{ $vfTrigger->badge($vfTable->getActiveFiltersCount()) }}
                </x-slot>

                {{ $vfTrigger->getModalContent() }}

                {{ $vfForm }}

                {{ $vfTrigger->getModalContentFooter() }}
            </x-filament::modal>
        @else
            <x-filament::dropdown
                :max-height="$vfMaxHeight"
                placement="bottom-end"
                shift
                :flip="false"
                :width="$vfWidth ?? Width::ExtraSmall"
                :wire:key="$vfLivewire->getId() . '.table.filters'"
                class="fi-ta-filters-dropdown"
            >
                <x-slot name="trigger">
                    {{ $vfTrigger->badge($vfTable->getActiveFiltersCount()) }}
                </x-slot>

                <x-filament-tables::filters
                    :apply-action="$vfApply"
                    :form="$vfForm"
                    :reset-action-position="$vfResetPosition"
                />
            </x-filament::dropdown>
        @endif
    </template>
@endif
