<?php

declare(strict_types=1);

return [

    /*
     * Debounce (milliseconds) for the island's search inputs.
     */
    'debounce_ms' => 300,

    /*
     * `Cache-Control: private, max-age=...` for data endpoint responses.
     * 0 disables HTTP caching (default). The endpoint is side-effect free,
     * so short per-user caching is safe if your data tolerates it.
     */
    'cache_ttl' => 0,

    /*
     * Custom column serializers, PREPENDED to the built-in chain
     * (text/badge, icon, image, html fallback). Each class must implement
     * \Zvizvi\Villament\Support\ColumnSerializers\ColumnSerializer.
     * Pair each custom cell `type` with a JS component registered via
     * `window.Villament.registerCellComponent(type, component)`.
     */
    'column_serializers' => [],

];
