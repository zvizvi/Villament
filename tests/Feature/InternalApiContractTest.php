<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Panel;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\AssetManager;
use Filament\Support\View\ComponentAttributeBag;
use Filament\Support\View\Components\ButtonComponent;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
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
    // Reordering records is handed to the stock table.
    [ListRecords::class, 'toggleTableReordering'],
    [ListRecords::class, 'isTableReordering'],
    [ListRecords::class, 'reorderTable'],
    [Table::class, 'isReorderable'],
    [Table::class, 'getReorderRecordsTriggerAction'],
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
    [Table::class, 'getFiltersResetAction'],
    [Table::class, 'hasLoadingSkeleton'],
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
    // The empty state is Filament-native (teleported); the partial calls all
    // of these itself.
    [Table::class, 'getEmptyState'],
    [Table::class, 'getEmptyStateHeading'],
    [Table::class, 'getEmptyStateDescription'],
    [Table::class, 'getEmptyStateActions'],
    [Table::class, 'getHeadingTag'],
    // `recordClasses()`, resolved per record in RecordSerializer.
    [Table::class, 'getRecordClasses'],
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
    // `ClientRepeater` (src/Forms): the structural actions it rewires, the
    // render it wraps, and the state it fills the template item through.
    [Repeater::class, 'toEmbeddedHtml'],
    [Repeater::class, 'getAlpineAttributes'],
    [Repeater::class, 'getAddAction'],
    [Repeater::class, 'getAddBetweenAction'],
    [Repeater::class, 'getCloneAction'],
    [Repeater::class, 'getDeleteAction'],
    [Repeater::class, 'getMoveUpAction'],
    [Repeater::class, 'getMoveDownAction'],
    [Repeater::class, 'getReorderAction'],
    [Repeater::class, 'getAddActionName'],
    [Repeater::class, 'getAddBetweenActionName'],
    [Repeater::class, 'getCloneActionName'],
    [Repeater::class, 'getDeleteActionName'],
    [Repeater::class, 'getMoveUpActionName'],
    [Repeater::class, 'getMoveDownActionName'],
    [Repeater::class, 'getReorderActionName'],
    [Repeater::class, 'getAction'],
    [Repeater::class, 'getChildSchema'],
    [Repeater::class, 'clearCachedChildSchemas'],
    [Repeater::class, 'getRawState'],
    [Repeater::class, 'rawState'],
    [Repeater::class, 'getStatePath'],
    [Repeater::class, 'isAddable'],
    [Repeater::class, 'isCloneable'],
    [Repeater::class, 'isDisabled'],
    [Repeater::class, 'isLive'],
    [Repeater::class, 'isTable'],
    [Repeater::class, 'isSimple'],
    [Repeater::class, 'collapsed'],
    [Repeater::class, 'getLabelBetweenItems'],
    [Repeater::class, 'getMinItems'],
    [Repeater::class, 'getMaxItems'],
    [Repeater::class, 'hasItemNumbers'],
    [Schema::class, 'fill'],
    [Schema::class, 'getFlatComponents'],
    // `ActionBehavior`: what tells a restyled action from a re-programmed one.
    [Action::class, 'getActionFunction'],
    [Action::class, 'isLivewireClickHandlerEnabled'],
    [Action::class, 'livewireClickHandlerEnabled'],
    [Action::class, 'extraAttributes'],
    [Action::class, 'isConfirmationRequired'],
    [Action::class, 'shouldOpenModal'],
    [Action::class, 'getLivewireEventClickHandler'],
    [Action::class, 'getCustomAlpineClickHandler'],
    // `CloneSafety`: the plain native select is the only clone-safe Select.
    [Select::class, 'isNative'],
    [Select::class, 'isSearchable'],
    [Select::class, 'isMultiple'],
    [Select::class, 'isHtmlAllowed'],
    [Select::class, 'getRelationshipName'],
    [AssetManager::class, 'getAlpineComponentSrc'],
    // Client rendering: the shells, the per-row slot and the error variants.
    [Repeater::class, 'getItems'],
    [Schema::class, 'getClone'],
    [Schema::class, 'components'],
    [Schema::class, 'getComponents'],
    [SchemaComponent::class, 'toSchemaHtml'],
    [SchemaComponent::class, 'getChildSchemas'],
    [SchemaComponent::class, 'getKey'],
    [Field::class, 'areHtmlValidationMessagesAllowed'],
    [Field::class, 'shouldShowAllValidationMessages'],
    [Field::class, 'hasErrorForPath'],
    [LivewireManager::class, 'isLivewireRequest'],
    // `table()`: the reorder column's condition and the actions column's.
    [Repeater::class, 'getTableColumns'],
    [Repeater::class, 'isReorderableWithButtons'],
    [Repeater::class, 'isReorderableWithDragAndDrop'],
    [Repeater::class, 'getExtraItemActions'],
    [Repeater::class, 'isDeletable'],
    // Relationship tables (`HasVillamentRelationTable`, the relation endpoint).
    [RelationManager::class, 'bootCanAuthorizeAccess'],
    [RelationManager::class, 'canViewForRecord'],
    [RelationManager::class, 'getDefaultProperties'],
    [RelationManager::class, 'getPageClass'],
    [RelationManager::class, 'getOwnerRecord'],
    [RelationManager::class, 'isReadOnly'],
    [RelationManager::class, 'content'],
    [RelationManager::class, 'getTabsContentComponent'],
    [RelationManager::class, 'canView'],
    [RelationManager::class, 'canEdit'],
    [RelationManager::class, 'canDelete'],
    [ManageRelatedRecords::class, 'content'],
    [ManageRelatedRecords::class, 'getRelationManagersContentComponent'],
    [ManageRelatedRecords::class, 'getRelationManagers'],
    [ManageRelatedRecords::class, 'canView'],
    [ManageRelatedRecords::class, 'canEdit'],
    [ManageRelatedRecords::class, 'canDelete'],
    [EditRecord::class, 'getRelationManagers'],
    [ViewRecord::class, 'getRelationManagers'],
    [ResourcePage::class, 'resolveRecord'],
    [ResourcePage::class, 'authorizeResourceAccess'],
    [ResourcePage::class, 'canAccess'],
    [Resource::class, 'resolveRecordRouteBinding'],
    [Resource::class, 'getRecordRouteKeyName'],
    [RelationGroup::class, 'ownerRecord'],
    [RelationGroup::class, 'pageClass'],
    [RelationGroup::class, 'getManagers'],
    [RelationManagerConfiguration::class, 'getProperties'],
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
    // Written by the harness before boot, as Livewire's mount would.
    [RelationManager::class, 'ownerRecord'],
    [RelationManager::class, 'pageClass'],
    [ManageRelatedRecords::class, 'record'],
    [ResourcePage::class, 'parentRecord'],
    [RelationManagerConfiguration::class, 'relationManager'],
    // The same table state, on the relationship table components.
    [RelationManager::class, 'tableSort'],
    [RelationManager::class, 'tableFilters'],
    [RelationManager::class, 'tableRecordsPerPage'],
    [RelationManager::class, 'activeTab'],
    [ManageRelatedRecords::class, 'tableSort'],
    [ManageRelatedRecords::class, 'tableFilters'],
    [ManageRelatedRecords::class, 'activeTab'],
    // Read (or overridden around) by `ClientRepeater` / `ActionBehavior`.
    [Repeater::class, 'isAddable'],
    [Repeater::class, 'isCloneable'],
    [Repeater::class, 'isCollapsed'],
    [Repeater::class, 'generateUuidUsing'],
    [Repeater::class, 'afterStateUpdated'],
    [Action::class, 'before'],
    [Action::class, 'after'],
    [Action::class, 'beforeFormFilled'],
    [Action::class, 'afterFormFilled'],
    [Action::class, 'beforeFormValidated'],
    [Action::class, 'afterFormValidated'],
    [Action::class, 'schema'],
];

it('still finds every Filament method the package calls', function (string $class, string $method): void {
    expect(method_exists($class, $method))
        ->toBeTrue("Filament internal API drift: method [{$class}::{$method}()] no longer exists.");
})->with(array_map(fn (array $pair): array => [$pair[0], $pair[1]], $methodContract));

it('still finds every Livewire state property the package injects', function (string $class, string $property): void {
    expect(property_exists($class, $property))
        ->toBeTrue("Filament internal API drift: property [{$class}::\${$property}] no longer exists.");
})->with(array_map(fn (array $pair): array => [$pair[0], $pair[1]], $propertyContract));

it('still names the relation manager render hooks the island keeps in place', function (string $constant): void {
    expect(defined(PanelsRenderHook::class.'::'.$constant))
        ->toBeTrue("Filament internal API drift: render hook [PanelsRenderHook::{$constant}] no longer exists.");
})->with([
    'RESOURCE_RELATION_MANAGER_BEFORE',
    'RESOURCE_RELATION_MANAGER_AFTER',
    'RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_BEFORE',
    'RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_AFTER',
]);

it('still composes a relation manager as stock content the island replaces one-for-one', function (): void {
    // The trait's `content()` mirrors stock's component list with the island
    // in the EmbeddedTable's place; if stock grows a component, mirror it.
    $source = (string) file_get_contents((new ReflectionMethod(RelationManager::class, 'content'))->getFileName());

    expect($source)
        ->toContain('RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_AFTER),');
});

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

it('still declares every stock structural repeater action inline in Repeater.php', function (): void {
    // `ActionBehavior` recognises an unmodified action by its callback's
    // file. If Filament moves those closures, every action would silently
    // fall back to Livewire — this catches it.
    $file = (new ReflectionClass(Repeater::class))->getFileName();
    $source = file_get_contents($file);

    foreach (['getAddAction', 'getAddBetweenAction', 'getCloneAction', 'getDeleteAction', 'getMoveUpAction', 'getMoveDownAction', 'getReorderAction'] as $method) {
        $body = (new ReflectionMethod(Repeater::class, $method));
        $lines = implode('', array_slice(file($file), $body->getStartLine() - 1, $body->getEndLine() - $body->getStartLine() + 1));

        expect(str_contains($lines, '->action(function'))->toBeTrue("Filament internal API drift: [Repeater::{$method}()] no longer defines its action() callback inline.");
    }

    expect($source)->toContain('x-sortable-item=')
        ->and(class_exists(AlpineComponent::class))->toBeTrue();
});

it('still renders the repeater markup ClientRepeater cuts its template from', function (): void {
    $source = file_get_contents((new ReflectionClass(Repeater::class))->getFileName());

    foreach (['$hasReorderColumn = ($itemCount > 1)', '$hasActionsColumn = count($extraItemActions) || $isCloneable || $isDeletable', 'fi-fo-table-repeater-add', 'fi-fo-table-repeater-actions', '<tbody x-sortable', 'class="fi-fo-repeater-item-content"', 'class="fi-fo-simple-repeater-item-content"', 'data-reorder-owner', 'x-ref\' => \'reorderItems', 'fi-fo-repeater-add', 'fi-fo-simple-repeater-add', 'data-reorder-description', 'fi-fo-repeater-add-between-items-ctn', 'repeaterFormComponent('] as $marker) {
        expect(str_contains($source, $marker))->toBeTrue("Filament internal API drift: Repeater markup no longer contains [{$marker}].");
    }
});

it('still renders each schema component in the wrapper client rendering places units by', function (): void {
    $source = file_get_contents((new ReflectionClass(SchemaComponent::class))->getFileName());
    $wrapper = file_get_contents((new ReflectionClass(Repeater::class))->getFileName());

    expect(str_contains($source, 'wire:partial="schema-component::'))->toBeTrue('Filament internal API drift: schema components no longer render a [wire:partial="schema-component::{key}"] wrapper.')
        ->and(str_contains(file_get_contents(dirname((new ReflectionClass(Repeater::class))->getFileName(), 3).'/resources/views/components/field-wrapper.blade.php'), 'data-validation-error'))->toBeTrue('Filament internal API drift: the field wrapper no longer marks its error with [data-validation-error].')
        ->and(str_contains($wrapper, '$item->toHtml()'))->toBeTrue('Filament internal API drift: Repeater no longer renders an item through [$item->toHtml()].');
});

it('still swaps in the stock table through EmbeddedTable and names the trigger flag isReordering', function (): void {
    $parameters = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(Table::class, 'getReorderRecordsTriggerAction'))->getParameters(),
    );

    expect(class_exists(EmbeddedTable::class))->toBeTrue('Filament internal API drift: [EmbeddedTable] is gone.')
        ->and($parameters)->toBe(['isReordering']);
});
