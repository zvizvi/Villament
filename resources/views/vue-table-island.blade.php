<div class="fi-vue-table">
    <div data-vue-table wire:ignore>
        <script type="application/json" data-vue-table-config>{!! json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
    </div>

    {{--
        Filament's page view renders the action modals ONLY for non-HasTable
        components (page/index.blade.php) — for table pages they come from the
        stock table view, which the island replaces. Render them here instead,
        Livewire-morphable (NOT inside the wire:ignore mount).

        This replicates actions::components/modals.blade.php using
        $getLivewire() — inside a schema component view, `$this` is not the
        Livewire page, so the stock <x-filament-actions::modals /> renders
        empty. Pinned by a contract test; the double-render guard
        (hasActionsModalRendered) is honored both ways.
    --}}
    @php
        $vueTableLivewire = $getLivewire();
    @endphp
    @if ($vueTableLivewire instanceof \Filament\Actions\Contracts\HasActions && (! $vueTableLivewire->hasActionsModalRendered))
        <div
            wire:partial="action-modals"
            x-data="filamentActionModals({
                        livewireId: @js($vueTableLivewire->getId()),
                    })"
            style="height: 0"
        >
            @foreach ($vueTableLivewire->getMountedActions() as $action)
                @if ((! $loop->last) || $vueTableLivewire->mountedActionShouldOpenModal())
                    {{ $action->toModalHtmlable() }}
                @endif
            @endforeach
        </div>

        @php
            $vueTableLivewire->hasActionsModalRendered = true;
        @endphp
    @endif

    @if ($withNativeFilters)
        {{--
            Filament's own complete filters panel (heading + every native field
            type + reset + apply), kept mounted and Livewire-morphable (NOT
            inside the wire:ignore mount) but visually hidden. The Vue island's
            funnel trigger toggles it open; on apply/reset/remove the Livewire
            commit hook drives a Vue refetch. This is byte-identical to the
            stock filters dropdown content — no filter UI exists in Vue.
        --}}
        @php
            $vueTableFiltersTable = $getLivewire()->getTable();

            // Panel width, resolved like the stock dropdown: explicit
            // filtersFormWidth() first, then the width implied by
            // filtersFormColumns() (stock maps ints only — a responsive array
            // like ['sm' => 2, 'lg' => 4] is mapped here via its largest
            // breakpoint value, where stock falls back to ExtraSmall).
            $vueTableFiltersWidth = $vueTableFiltersTable->getFiltersFormWidth();

            if (blank($vueTableFiltersWidth)) {
                $vueTableFiltersColumns = $vueTableFiltersTable->getFiltersFormColumns();

                $vueTableFiltersWidth = match (is_array($vueTableFiltersColumns) ? max($vueTableFiltersColumns) : $vueTableFiltersColumns) {
                    2 => \Filament\Support\Enums\Width::TwoExtraLarge,
                    3 => \Filament\Support\Enums\Width::FourExtraLarge,
                    4 => \Filament\Support\Enums\Width::SixExtraLarge,
                    default => \Filament\Support\Enums\Width::ExtraSmall,
                };
            }

            if (is_string($vueTableFiltersWidth)) {
                $vueTableFiltersWidth = \Filament\Support\Enums\Width::tryFrom($vueTableFiltersWidth) ?? $vueTableFiltersWidth;
            }

            $vueTableFiltersMaxHeight = $vueTableFiltersTable->getFiltersFormMaxHeight();
        @endphp

        <div
            data-vue-table-filters-panel
            wire:key="{{ $getLivewire()->getId() }}.vue-table-filters-panel"
            @class([
                'fi-vue-table-filters-panel',
                ($vueTableFiltersWidth instanceof \Filament\Support\Enums\Width) ? "fi-width-{$vueTableFiltersWidth->value}" : $vueTableFiltersWidth,
                'fi-scrollable' => $vueTableFiltersMaxHeight,
            ])
            @style([
                'display: none',
                ('max-height: ' . e($vueTableFiltersMaxHeight)) => $vueTableFiltersMaxHeight,
            ])
        >
            {{--
                Stock scopes the ENTIRE filters styling block (grid, gap-y,
                header/heading/actions layout, and the dropdown `p-6` padding)
                under `.fi-ta-ctn .fi-ta-filters-dropdown`. This panel lives
                OUTSIDE the Vue-owned `.fi-ta-ctn` (it must stay Livewire-
                morphable, i.e. out of the wire:ignore mount), so those rules
                would miss it. Reconstruct the exact ancestor chain with
                `display: contents` wrappers: they carry the classes the
                descendant selectors need but generate no box, so every native
                rule applies verbatim — no custom filter CSS.
            --}}
            <div class="fi-ta-ctn" style="display: contents">
                <div class="fi-ta-filters-dropdown" style="display: contents">
                    <x-filament-tables::filters
                        :apply-action="$vueTableFiltersTable->getFiltersApplyAction()"
                        :form="$getLivewire()->getTableFiltersForm()"
                        :reset-action-position="$vueTableFiltersTable->getFiltersResetActionPosition()"
                    />
                </div>
            </div>
        </div>
    @endif
</div>
