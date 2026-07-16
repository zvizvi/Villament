<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Panel;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\LivewireManager;

/**
 * Every non-public / internal Filament API the package touches, pinned. If a
 * Filament release removes or renames one of these, this file fails loudly
 * with the exact member name — fix goes into a version-conditional adapter.
 */
$methodContract = [
    [ListRecords::class, 'bootedInteractsWithTable'],
    [ListRecords::class, 'getTableRecords'],
    [ListRecords::class, 'getTableRecordKey'],
    [ListRecords::class, 'getFilteredTableQuery'],
    [ListRecords::class, 'flushCachedTableRecords'],
    [ListRecords::class, 'getTablePaginationPageName'],
    [ListRecords::class, 'setPage'],
    [ListRecords::class, 'getTablePage'],
    [ListRecords::class, 'getTableRecordsPerPage'],
    [ListRecords::class, 'getTable'],
    [ListRecords::class, 'content'],
    [ListRecords::class, 'getTabsContentComponent'],
    [ListRecords::class, 'getAllTableSummaryQuery'],
    [ListRecords::class, 'getPageTableSummaryQuery'],
    [ListRecords::class, 'getTableSummarySelectedState'],
    [ListRecords::class, 'removeTableFilter'],
    [ListRecords::class, 'resetTableFiltersForm'],
    [Table::class, 'getFiltersApplyAction'],
    [Table::class, 'getFiltersResetActionPosition'],
    [Table::class, 'getColumns'],
    [Table::class, 'getVisibleColumns'],
    [Table::class, 'getColumn'],
    [Table::class, 'getFilters'],
    [Table::class, 'getFilter'],
    [Table::class, 'getDefaultSortColumn'],
    [Table::class, 'getDefaultSortDirection'],
    [Table::class, 'isSearchable'],
    [Table::class, 'getPaginationPageOptions'],
    [Table::class, 'getDefaultPaginationPageOption'],
    [Table::class, 'getPaginationMode'],
    [Column::class, 'record'],
    [Column::class, 'getState'],
    [TextColumn::class, 'formatState'],
    [Column::class, 'getUrl'],
    [Column::class, 'hasStateBasedUrls'],
    [Column::class, 'shouldOpenUrlInNewTab'],
    [Column::class, 'isSortable'],
    [Column::class, 'isIndividuallySearchable'],
    [Column::class, 'isToggleable'],
    [Column::class, 'isToggledHiddenByDefault'],
    [Column::class, 'getAlignment'],
    [Column::class, 'isHidden'],
    [Column::class, 'toHtml'],
    [TextColumn::class, 'isBadge'],
    [TextColumn::class, 'isHtml'],
    [TextColumn::class, 'getColor'],
    [TextColumn::class, 'getIcon'],
    [TextColumn::class, 'getDescriptionBelow'],
    [IconColumn::class, 'getIcon'],
    [IconColumn::class, 'getColor'],
    [ImageColumn::class, 'getImageUrl'],
    [ImageColumn::class, 'isCircular'],
    [ImageColumn::class, 'getImageHeight'],
    [ListRecords::class, 'mountAction'],
    [ListRecords::class, 'applyTableColumnManager'],
    [ListRecords::class, 'resetTableColumnManager'],
    [ListRecords::class, 'getDefaultTableColumnState'],
    [Table::class, 'getRecordActions'],
    [Table::class, 'getFlatRecordActions'],
    [Table::class, 'getToolbarActions'],
    [ActionGroup::class, 'getFlatActions'],
    [Action::class, 'record'],
    [Action::class, 'isVisible'],
    [Action::class, 'getUrl'],
    [Action::class, 'isDisabled'],
    [LivewireManager::class, 'new'],
    [Resource::class, 'canViewAny'],
    [Resource::class, 'canView'],
    [Resource::class, 'canEdit'],
    [Resource::class, 'canDelete'],
    [Resource::class, 'getSlug'],
    [Resource::class, 'getPages'],
    [PageRegistration::class, 'getPage'],
    [Panel::class, 'authenticatedRoutes'],
    [Panel::class, 'route'],
    [Panel::class, 'getResources'],
    [Panel::class, 'hasTenancy'],
    [Panel::class, 'authenticatedTenantRoutes'],
];

$propertyContract = [
    [ListRecords::class, 'tableSort'],
    [ListRecords::class, 'tableSearch'],
    [ListRecords::class, 'tableColumnSearches'],
    [ListRecords::class, 'tableFilters'],
    [ListRecords::class, 'tableDeferredFilters'],
    [ListRecords::class, 'tableRecordsPerPage'],
    [ListRecords::class, 'tableColumns'],
    [ListRecords::class, 'selectedTableRecords'],
    [ListRecords::class, 'deselectedTableRecords'],
    [ListRecords::class, 'isTrackingDeselectedTableRecords'],
];

it('still finds every Filament method the package calls', function (string $class, string $method): void {
    expect(method_exists($class, $method))
        ->toBeTrue("Filament internal API drift: method [{$class}::{$method}()] no longer exists.");
})->with(array_map(fn (array $pair): array => [$pair[0], $pair[1]], $methodContract));

it('still finds every Livewire state property the package injects', function (string $class, string $property): void {
    expect(property_exists($class, $property))
        ->toBeTrue("Filament internal API drift: property [{$class}::\${$property}] no longer exists.");
})->with(array_map(fn (array $pair): array => [$pair[0], $pair[1]], $propertyContract));

it('still finds the icon rendering helper', function (): void {
    expect(function_exists('Filament\Support\generate_icon_html'))->toBeTrue();
});
