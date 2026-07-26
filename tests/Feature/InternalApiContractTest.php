<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Panel;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\View\ComponentAttributeBag;
use Filament\Support\View\Components\ButtonComponent;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
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
    [ListRecords::class, 'shouldRenderTrailingGroupedTableSummary'],
    [ListRecords::class, 'removeTableFilter'],
    [ListRecords::class, 'resetTableFiltersForm'],
    [ListRecords::class, 'removeTableFilters'],
    // Search options carried into the island.
    [Table::class, 'getSearchDebounce'],
    [Table::class, 'isSearchOnBlur'],
    [Table::class, 'getSearchPlaceholder'],
    // Session persistence: the endpoint writes what the `updated*` hooks would.
    [Table::class, 'persistsSortInSession'],
    [Table::class, 'persistsSearchInSession'],
    [Table::class, 'persistsColumnSearchesInSession'],
    [ListRecords::class, 'getTableSortSessionKey'],
    [ListRecords::class, 'getTableSearchSessionKey'],
    [ListRecords::class, 'getTableColumnSearchesSessionKey'],
    // The column manager is rendered natively from our Blade partial, so its
    // whole configuration surface is called there.
    [Table::class, 'hasColumnManager'],
    [Table::class, 'hasToggleableColumns'],
    [Table::class, 'hasReorderableColumns'],
    [Table::class, 'getColumnManagerLayout'],
    [Table::class, 'getColumnManagerTriggerAction'],
    [Table::class, 'getColumnManagerApplyAction'],
    [Table::class, 'getColumnManagerColumns'],
    [Table::class, 'getColumnManagerWidth'],
    [Table::class, 'getColumnManagerMaxHeight'],
    [Table::class, 'getColumnManagerResetActionPosition'],
    [Table::class, 'getReorderAnimationDuration'],
    [Table::class, 'getFiltersApplyAction'],
    [Table::class, 'getFiltersResetActionPosition'],
    [Table::class, 'getFiltersFormWidth'],
    [Table::class, 'getFiltersFormColumns'],
    [Table::class, 'getFiltersFormMaxHeight'],
    // Summary row toggles (`summaries(pageCondition:, allTableCondition:)`).
    [Table::class, 'hasPageSummary'],
    [Table::class, 'hasAllTableSummary'],
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
    [Table::class, 'getGroups'],
    // Column groups: `getColumnGroups()` is keyed by `e($label)`, exactly as
    // the column-manager state keys its group entries — the island matches the
    // two on that key.
    [Table::class, 'getColumnGroups'],
    [ColumnGroup::class, 'getLabel'],
    [ColumnGroup::class, 'canHeaderWrap'],
    [ColumnGroup::class, 'getAlignment'],
    [ColumnGroup::class, 'getHiddenFrom'],
    [ColumnGroup::class, 'getVisibleFrom'],
    [Table::class, 'getGroup'],
    [Table::class, 'getGrouping'],
    [Table::class, 'getDefaultGroup'],
    [Table::class, 'isDefaultGroupSelectable'],
    [Table::class, 'isGroupingDirectionSettingHidden'],
    [Table::class, 'areGroupingSettingsHidden'],
    [Table::class, 'areGroupingSettingsInDropdownOnDesktop'],
    [Table::class, 'areGroupsCollapsedByDefault'],
    [Table::class, 'isGroupsOnly'],
    [Table::class, 'getGroupRecordsTriggerAction'],
    [Group::class, 'getId'],
    [Group::class, 'getColumn'],
    [Group::class, 'groupQuery'],
    [Group::class, 'scopeQuery'],
    [Group::class, 'isCollapsible'],
    [Group::class, 'getLabel'],
    [Group::class, 'getStringKey'],
    [Group::class, 'getTitle'],
    [Group::class, 'getDescription'],
    [Group::class, 'isTitlePrefixedWithLabel'],
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
    // Header/cell modifiers — the class set stock builds into its `<th>`/`<td>`.
    [Column::class, 'getVerticalAlignment'],
    [Column::class, 'canHeaderWrap'],
    [Column::class, 'canGrow'],
    [Column::class, 'getWidth'],
    [Column::class, 'getGroup'],
    [Column::class, 'getHiddenFrom'],
    [Column::class, 'getVisibleFrom'],
    // Cell interaction + per-cell display, resolved server-side.
    [Column::class, 'getAction'],
    [Column::class, 'isClickDisabled'],
    [Column::class, 'rowLoop'],
    [Column::class, 'recordKey'],
    [Column::class, 'getTooltip'],
    [Column::class, 'getEmptyTooltip'],
    [Column::class, 'getHeaderTooltip'],
    [Column::class, 'getPlaceholder'],
    [Column::class, 'getExtraAttributes'],
    [Column::class, 'getExtraCellAttributes'],
    [Column::class, 'getExtraHeaderAttributes'],
    [Column::class, 'hasDynamicAlignment'],
    [Column::class, 'hasDynamicVerticalAlignment'],
    [Column::class, 'hasDynamicHiddenFrom'],
    [Column::class, 'hasDynamicVisibleFrom'],
    [TextColumn::class, 'getDescriptionAbove'],
    [ImageColumn::class, 'getDefaultImageUrl'],
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
    [Table::class, 'getAction'],
    [Table::class, 'getRecordActions'],
    [Table::class, 'getFlatRecordActions'],
    [Table::class, 'getToolbarActions'],
    [Table::class, 'getHeaderActions'],
    [Table::class, 'getHeaderActionsPosition'],
    // `header()` — the custom view, rendered by Filament and teleported in.
    [Table::class, 'getHeader'],
    [Table::class, 'getHeading'],
    [Table::class, 'getDescription'],
    // Record-actions placement (`recordActions(position:)` + its options).
    [Table::class, 'getRecordActionsPosition'],
    [Table::class, 'getRecordActionsAlignment'],
    [Table::class, 'getRecordActionsColumnLabel'],
    // The record-selection surface, all of it read by the schema serializer.
    [Table::class, 'isSelectionEnabled'],
    [Table::class, 'isSelectionDisabled'],
    [Table::class, 'getMaxSelectableRecords'],
    [Table::class, 'selectsCurrentPageOnly'],
    [Table::class, 'selectsGroupsOnly'],
    [Table::class, 'canTrackDeselectedRecords'],
    [Table::class, 'checksIfRecordIsSelectable'],
    [Table::class, 'isRecordSelectable'],
    [ListRecords::class, 'getAllSelectableTableRecordsCount'],
    [ListRecords::class, 'getAllSelectableTableRecordKeys'],
    // `getActions()` is what stamps a group's children with the dropdown-item
    // view; `getFlatActions()` returns the same objects UNstamped.
    [ActionGroup::class, 'getActions'],
    [ActionGroup::class, 'getFlatActions'],
    [ActionGroup::class, 'hasNonBulkAction'],
    [Action::class, 'record'],
    [Action::class, 'isVisible'],
    [Action::class, 'getUrl'],
    [Action::class, 'isDisabled'],
    // The trigger surface `ActionTriggerSerializer` reads to rebuild each
    // action's markup client-side.
    [Action::class, 'getView'],
    [Action::class, 'getSize'],
    [Action::class, 'getColor'],
    [Action::class, 'getIcon'],
    [Action::class, 'getIconSize'],
    [Action::class, 'getIconPosition'],
    [Action::class, 'getGroupedIcon'],
    [Action::class, 'getTableIcon'],
    [Action::class, 'isLabelHidden'],
    [Action::class, 'isOutlined'],
    [Action::class, 'getBadge'],
    [Action::class, 'getBadgeColor'],
    [Action::class, 'getTooltip'],
    [Action::class, 'getExtraAttributes'],
    [Action::class, 'shouldOpenUrlInNewTab'],
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
    [ListRecords::class, 'tableGrouping'],
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

it('still finds every Action trigger view constant', function (string $constant): void {
    expect(defined(Action::class.'::'.$constant))
        ->toBeTrue("Filament internal API drift: constant [Action::{$constant}] no longer exists.");
})->with(['BADGE_VIEW', 'BUTTON_VIEW', 'GROUPED_VIEW', 'ICON_BUTTON_VIEW', 'LINK_VIEW']);

it('still finds the component color bag the trigger serializer builds classes with', function (): void {
    // `ActionTriggerSerializer` rebuilds each trigger's class string through
    // the very same bag chain Filament's own `generate*Html()` methods use.
    expect(method_exists(ComponentAttributeBag::class, 'color'))->toBeTrue()
        ->and(method_exists(ButtonComponent::class, 'make'))->toBeTrue();
});
