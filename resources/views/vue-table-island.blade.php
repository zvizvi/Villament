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
    @php($vueTableLivewire = $getLivewire())
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

        @php($vueTableLivewire->hasActionsModalRendered = true)
    @endif

    @if ($withFallbackFilterForm)
        {{--
            The page's original Livewire filter form, kept mounted (and
            Livewire-morphable — no wire:ignore here) but visually hidden.
            The Vue island toggles it open for `livewire-fallback` filters;
            state changes flow back through the Livewire commit hook.
        --}}
        <div
            data-vue-table-fallback-filters
            wire:key="{{ $getLivewire()->getId() }}.vue-table-fallback-filters"
            style="display: none;"
            class="fi-vue-table-fallback-filters"
        >
            {{ $getLivewire()->getTableFiltersForm() }}

            @if ($getLivewire()->getTable()->hasDeferredFilters())
                <button
                    type="button"
                    class="fi-btn fi-size-md fi-color fi-color-primary fi-btn-color-primary"
                    wire:click="applyTableFilters"
                    data-vue-table-apply-filters
                >
                    {{ __('filament-tables::table.filters.actions.apply.label') }}
                </button>
            @endif
        </div>
    @endif
</div>
