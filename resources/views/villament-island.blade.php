<div class="villament">
    {{--
        Hidden, never removed, while the stock table reorders the records in
        its place: the Vue app and the native slots it adopted stay mounted,
        so pressing "done" only shows it again (and the toggle's commit
        refetches it). The hiding sits on a wrapper because `wire:ignore`
        freezes the island element's own attributes too.
    --}}
    <div @style(['display: none' => $isReordering])>
        <div data-villament wire:ignore>
            <script type="application/json" data-villament-config>{!! json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
        </div>
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
        @include('villament::partials.filters')
    @endif

    @if ($withNativeColumnManager)
        @include('villament::partials.column-manager')
    @endif

    @if ($withNativeReorderTrigger)
        @include('villament::partials.reorder-trigger')
    @endif

    @if ($withNativeHeader)
        @include('villament::partials.header')
    @endif

    @if ($renderHooks !== [])
        @include('villament::partials.render-hooks')
    @endif

    @if ($withColumnFilterSlots)
        @include('villament::partials.column-filter-slots')
    @endif

    {{--
        The empty state is ALWAYS rendered, unconditionally: whether the table
        is empty is decided by the island's own fetches, long after Blade ran.
        The island keeps the slot hidden until it has no records.
    --}}
    @include('villament::partials.empty-state')
</div>
