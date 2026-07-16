<?php

declare(strict_types=1);

return [

    /*
     * Debounce (milliseconds) for the island's search inputs.
     */
    'debounce_ms' => 300,

    /*
     * How the filter panel is rendered:
     *
     * - 'livewire' (default): the panel is Filament's own native filters
     *   dropdown (heading, all field types, reset, apply — full fidelity, any
     *   filter type). The Vue island only opens it and refetches records when
     *   the filter state changes. Zero per-filter-type Vue reimplementation.
     * - 'vue': filters render as native Vue controls (instant, no Livewire
     *   roundtrip), with the Livewire form kept as a fallback for filter types
     *   the island can't render.
     */
    'filters' => 'livewire',

    /*
     * `Cache-Control: private, max-age=...` for data endpoint responses.
     * 0 disables HTTP caching (default). The endpoint is side-effect free,
     * so short per-user caching is safe if your data tolerates it.
     */
    'cache_ttl' => 0,

    /*
     * Custom column serializers, PREPENDED to the built-in chain
     * (text/badge, icon, image, html fallback). Each class must implement
     * \Zvizvi\FilamentVueTable\Support\ColumnSerializers\ColumnSerializer.
     * Pair each custom cell `type` with a JS component registered via
     * `window.FilamentVueTable.registerCellComponent(type, component)`.
     */
    'column_serializers' => [],

    /*
     * Custom filter serializers, PREPENDED to the built-in chain
     * (ternary, select, livewire-fallback). Each class must implement
     * \Zvizvi\FilamentVueTable\Support\FilterSerializers\FilterSerializer.
     */
    'filter_serializers' => [],

];
