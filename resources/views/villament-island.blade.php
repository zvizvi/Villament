<div class="villament">
    <div data-villament wire:ignore>
        <script type="application/json" data-villament-config>{!! json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
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
            data-villament-filters-panel
            wire:key="{{ $getLivewire()->getId() }}.vue-table-filters-panel"
            {{--
                The bridge toggles this panel open by mutating its inline style
                + classes at runtime. It stays Livewire-morphable (the filter
                fields inside must update), but a live commit — e.g. under
                deferFilters(false) — would otherwise morph the container back
                to its closed server markup (display:none, no open classes) and
                snap the panel shut mid-interaction. `wire:ignore.self` freezes
                the container's OWN attributes across morphs while still morphing
                its children, so the open/closed state is JS-owned.
            --}}
            wire:ignore.self
            @class([
                'villament-filters-panel',
                ($vueTableFiltersWidth instanceof \Filament\Support\Enums\Width) ? "fi-width-{$vueTableFiltersWidth->value}" : $vueTableFiltersWidth,
                'fi-scrollable' => $vueTableFiltersMaxHeight,
            ])
            @style([
                'display: none',
                ('max-height: ' . e($vueTableFiltersMaxHeight)) => $vueTableFiltersMaxHeight,
            ])
        >
            {{--
                Stock scopes the ENTIRE filters styling block under `.fi-ta-ctn`
                (grid + gap-y, header/heading/actions layout) and the dropdown
                `p-6` padding one level deeper still, under
                `.fi-ta-ctn .fi-ta-header-toolbar .fi-ta-filters-dropdown`.
                This panel lives OUTSIDE the Vue-owned `.fi-ta-ctn` (it must
                stay Livewire-morphable, i.e. out of the wire:ignore mount), so
                those rules would miss it. Reconstruct the exact ancestor chain
                with `display: contents` wrappers: they carry the classes the
                descendant selectors need but generate no box (so the toolbar's
                own flex/border/padding and its `> *` child rules paint
                nothing), letting every native filter rule apply verbatim — no
                custom filter CSS.
            --}}
            <div class="fi-ta-ctn" style="display: contents">
                <div class="fi-ta-header-toolbar" style="display: contents">
                    <div class="fi-ta-filters-dropdown" style="display: contents">
                        <x-filament-tables::filters
                            :apply-action="$vueTableFiltersTable->getFiltersApplyAction()"
                            :form="$getLivewire()->getTableFiltersForm()"
                            :reset-action-position="$vueTableFiltersTable->getFiltersResetActionPosition()"
                        />
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
