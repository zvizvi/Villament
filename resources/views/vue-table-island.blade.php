<div class="fi-vue-table">
    <div data-vue-table wire:ignore>
        <script type="application/json" data-vue-table-config>{!! json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
    </div>

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
