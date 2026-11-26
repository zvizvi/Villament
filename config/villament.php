<?php

declare(strict_types=1);

return [

    /*
     * Debounce (milliseconds) for the island's search inputs. `null` follows
     * each table's own `searchDebounce()` (Filament's default: 500ms); set a
     * number here to override every table at once.
     */
    'debounce_ms' => null,

    /*
     * `Cache-Control: private, max-age=...` for data endpoint responses.
     * 0 disables HTTP caching (default). The endpoint is side-effect free,
     * so short per-user caching is safe if your data tolerates it.
     */
    'cache_ttl' => 0,

    /*
     * Keep a relation manager's table state (sort, search, filters, page,
     * per page) in the query string, as a List page does. Off by default,
     * as in stock Filament. The parameters are nested under the
     * relationship's name (`products[sort]`), so the managers of one page
     * do not collide, and are taken out of the URL when the manager's tab
     * is left. A single manager can decide for itself by overriding
     * `shouldSyncVillamentStateWithUrl()`.
     */
    'relation_manager_url_state' => false,

    /*
     * Custom column serializers, PREPENDED to the built-in chain
     * (text/badge, icon, image, html fallback). Each class must implement
     * \Zvizvi\Villament\Support\ColumnSerializers\ColumnSerializer.
     * Pair each custom cell `type` with a JS component registered via
     * `window.Villament.registerCellComponent(type, component)`.
     */
    'column_serializers' => [],

];
