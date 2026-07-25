<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

/**
 * Validated, whitelisted table interaction state extracted from a request.
 */
final readonly class TableState
{
    /**
     * @param  ?string  $sort  Filament v5 sort string in `column:direction` format
     * @param  array<string, string>  $columnSearches
     * @param  array<string, array<string, mixed>>|null  $filters  Filament filter form state, keyed by filter name
     * @param  ?string  $grouping  Filament v5 `tableGrouping` string: a group id, optionally suffixed `:asc`/`:desc`
     */
    public function __construct(
        public ?string $sort = null,
        public string $search = '',
        public array $columnSearches = [],
        public ?array $filters = null,
        public int $page = 1,
        public int|string|null $perPage = null,
        public ?string $cursor = null,
        public ?string $grouping = null,
    ) {}
}
