<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Icons\Heroicon;
use Filament\Support\View\Components\ButtonComponent;
use Filament\Support\View\SupportIconAlias;
use Filament\Tables\Columns\Column;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Filament\Tables\View\TablesIconAlias;

use function Filament\Support\generate_icon_html;
use function Filament\Support\get_component_color_classes;

/**
 * Serializes a Filament Table definition into the JSON schema payload the Vue
 * island consumes. Runs once per page load; the hash of its output doubles as
 * the drift-detection `schemaVersion`.
 */
final class SchemaSerializer
{
    /**
     * @var list<ColumnSerializers\ColumnSerializer>
     */
    private readonly array $columnSerializers;

    /**
     * @param  list<ColumnSerializers\ColumnSerializer>|null  $columnSerializers
     */
    public function __construct(
        ?array $columnSerializers = null,
        private readonly int $debounceMs = 300,
    ) {
        $this->columnSerializers = $columnSerializers ?? [
            new ColumnSerializers\IconColumnSerializer,
            new ColumnSerializers\ImageColumnSerializer,
            new ColumnSerializers\TextColumnSerializer,
            new ColumnSerializers\HtmlColumnSerializer,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Table $table): array
    {
        $defaultSortColumn = $table->getDefaultSortColumn();

        $columns = [];

        foreach ($table->getColumns() as $column) {
            if ($column->isHidden()) {
                continue;
            }

            $columns[] = $this->serializeColumn($column);
        }

        return [
            'columns' => $columns,
            'columnGroups' => $this->columnGroups($table),
            'defaultSort' => $defaultSortColumn === null ? null : [
                'column' => $defaultSortColumn,
                'direction' => $table->getDefaultSortDirection() ?? 'asc',
            ],
            'globallySearchable' => $table->isSearchable(),
            'striped' => $table->isStriped(),
            'reorderableColumns' => $table->hasReorderableColumns(),
            // Deferred (stock default): column toggles/reorders stage in the
            // panel and only hit the table on "Apply columns". Non-deferred:
            // each change applies immediately.
            'columnManagerDeferred' => $table->hasDeferredColumnManager(),
            'columnManagerApplyClasses' => implode(' ', get_component_color_classes(ButtonComponent::class, 'primary')),
            // The filters UI — trigger included — is Filament-native, rendered
            // in Blade and teleported into a Vue slot. The island only needs
            // the layout to know WHERE to render that slot: the toolbar for
            // Dropdown/Modal, or a positional slot for the inline layouts.
            'hasFilters' => $table->getFilters() !== [],
            'filtersLayout' => $table->getFiltersLayout()->name,
            'grouping' => $this->grouping($table),
            'pagination' => [
                'mode' => match ($table->getPaginationMode()) {
                    PaginationMode::Simple => 'simple',
                    PaginationMode::Cursor => 'cursor',
                    default => 'offset',
                },
                'pageOptions' => $table->getPaginationPageOptions(),
                'defaultPerPage' => $table->getDefaultPaginationPageOption(),
            ],
            'actions' => (new ActionSerializer)->serializeStructure($table),
            'debounceMs' => $this->debounceMs,
            'strings' => $this->strings($table),
            'icons' => $this->icons($table),
        ];
    }

    /**
     * UI icons rendered server-side through `generate_icon_html()` with the
     * exact same Heroicon + alias pairs Filament's own blades use, so the
     * markup is byte-identical to stock (mini/outline variants included) and
     * app-level `FilamentIcon` alias overrides keep working.
     *
     * @return array<string, string|null>
     */
    private function icons(Table $table): array
    {
        $isRtl = __('filament-panels::layout.direction') === 'rtl';

        return [
            'sort' => $this->iconHtml(Heroicon::ChevronDown, TablesIconAlias::HEADER_CELL_SORT_BUTTON),
            'sortAsc' => $this->iconHtml(Heroicon::ChevronUp, TablesIconAlias::HEADER_CELL_SORT_ASC_BUTTON),
            'sortDesc' => $this->iconHtml(Heroicon::ChevronDown, TablesIconAlias::HEADER_CELL_SORT_DESC_BUTTON),
            'search' => $this->iconHtml(Heroicon::MagnifyingGlass, TablesIconAlias::SEARCH_FIELD),
            'removeFilters' => $this->iconHtml(Heroicon::XMark, TablesIconAlias::FILTERS_REMOVE_ALL_BUTTON),
            'previous' => $this->iconHtml($isRtl ? Heroicon::ChevronRight : Heroicon::ChevronLeft),
            'next' => $this->iconHtml($isRtl ? Heroicon::ChevronLeft : Heroicon::ChevronRight),
            'empty' => $this->iconHtml($table->getEmptyStateIcon(), size: IconSize::Large),
            'columnManager' => $this->iconHtml(Heroicon::ViewColumns, TablesIconAlias::ACTIONS_COLUMN_MANAGER),
            'badgeDelete' => $this->iconHtml(Heroicon::XMark, SupportIconAlias::BADGE_DELETE_BUTTON, IconSize::ExtraSmall),
            'reorderHandle' => $this->iconHtml(Heroicon::Bars2, TablesIconAlias::REORDER_HANDLE),
            'group' => $this->iconHtml(Heroicon::RectangleStack, TablesIconAlias::ACTIONS_GROUP),
            'groupCollapse' => $this->iconHtml(Heroicon::ChevronUp, TablesIconAlias::GROUPING_COLLAPSE_BUTTON, IconSize::Small),
        ];
    }

    private function iconHtml(string|\BackedEnum $icon, ?string $alias = null, ?IconSize $size = null): ?string
    {
        return generate_icon_html($icon, $alias, size: $size)?->toHtml();
    }

    /**
     * UI strings resolved through Filament's own translations, so the island
     * follows the panel locale (including RTL languages) with zero config.
     *
     * @return array<string, string>
     */
    private function strings(Table $table): array
    {
        return [
            'searchPlaceholder' => __('filament-tables::table.fields.search.placeholder'),
            'searchLabel' => __('filament-tables::table.fields.search.label'),
            'filtersIndicator' => __('filament-tables::table.filters.indicator'),
            'filtersRemove' => __('filament-tables::table.filters.actions.remove.label'),
            'filtersRemoveAll' => __('filament-tables::table.filters.actions.remove_all.label'),
            'emptyHeading' => __('filament-tables::table.empty.heading', ['model' => $table->getPluralModelLabel()]),
            'paginationLabel' => __('filament::components/pagination.label'),
            'paginationOverview' => __('filament::components/pagination.overview'),
            'perPageLabel' => __('filament::components/pagination.fields.records_per_page.label'),
            'perPageAll' => __('filament::components/pagination.fields.records_per_page.options.all'),
            'previousLabel' => __('filament::components/pagination.actions.previous.label'),
            'nextLabel' => __('filament::components/pagination.actions.next.label'),
            'columnManagerHeading' => __('filament-tables::table.column_manager.heading'),
            // The trigger button's tooltip/aria is the ACTION label (e.g.
            // "Column manager" / "הצג עמודות"), not the panel heading.
            'columnManagerTrigger' => __('filament-tables::table.actions.column_manager.label'),
            'columnManagerApply' => __('filament-tables::table.column_manager.actions.apply.label'),
            'columnManagerReset' => __('filament-tables::table.column_manager.actions.reset.label'),
            'bulkSelectPage' => __('filament-tables::table.fields.bulk_select_page.label'),
            'bulkSelectRecord' => __('filament-tables::table.fields.bulk_select_record.label'),
            'bulkSelectGroup' => __('filament-tables::table.fields.bulk_select_group.label'),
            'selectedCount' => __('filament-tables::table.selection_indicator.selected_count'),
            'selectAll' => __('filament-tables::table.selection_indicator.actions.select_all.label'),
            'deselectAll' => __('filament-tables::table.selection_indicator.actions.deselect_all.label'),
            'groupTrigger' => __('filament-tables::table.actions.group.label'),
            'groupBy' => __('filament-tables::table.grouping.fields.group.label'),
            'groupDirection' => __('filament-tables::table.grouping.fields.direction.label'),
            'groupDirectionAsc' => __('filament-tables::table.grouping.fields.direction.options.asc'),
            'groupDirectionDesc' => __('filament-tables::table.grouping.fields.direction.options.desc'),
        ];
    }

    /**
     * Drift-detection hash. Column-manager reordering changes the order of
     * `schema.columns` PER USER SESSION — canonicalize it so the hash only
     * reacts to real definition changes (deploys), never to user state;
     * otherwise the island would hard-reload after every drag-reorder.
     *
     * @param  array<string, mixed>  $schema
     */
    public function version(array $schema): string
    {
        if (isset($schema['columns']) && is_array($schema['columns'])) {
            usort(
                $schema['columns'],
                fn (array $a, array $b): int => strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? '')),
            );
        }

        return hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeColumn(Column $column): array
    {
        $alignment = $column->getAlignment();
        $verticalAlignment = $column->getVerticalAlignment();
        $width = $column->getWidth();

        return [
            'key' => $column->getName(),
            'label' => (string) $column->getLabel(),
            'type' => $this->columnType($column),
            'sortable' => $column->isSortable(),
            'individuallySearchable' => $column->isIndividuallySearchable(),
            'toggleable' => $column->isToggleable(),
            'hiddenByDefault' => $column->isToggleable() && $column->isToggledHiddenByDefault(),
            'alignment' => $alignment instanceof Alignment ? $alignment->value : $alignment,
            // Vertical alignment is a CELL modifier only — stock never puts it
            // on the header.
            'verticalAlignment' => $verticalAlignment instanceof VerticalAlignment ? $verticalAlignment->value : $verticalAlignment,
            // The remaining flags drive the HEADER cell, mirroring the class
            // list stock builds for its `<th>` (`fi-growable` is suppressed by
            // an explicit width, and `canGrow()` defaults to false there).
            'width' => $width,
            'growable' => blank($width) && $column->canGrow(default: false),
            'grouped' => $column->getGroup() !== null,
            'wrapHeader' => $column->canHeaderWrap(),
            // Responsive visibility must land on the header AND the record
            // cells, or the two drift out of alignment at that breakpoint.
            'hiddenFrom' => $column->getHiddenFrom(),
            'visibleFrom' => $column->getVisibleFrom(),
        ];
    }

    /**
     * Display modifiers for the `ColumnGroup` header row, keyed exactly as
     * Filament's own column-manager state keys its group entries
     * (`e($group->getLabel())`) — the island already receives that state and
     * walks it for order and visibility, so this only has to supply what the
     * manager omits.
     *
     * `label` is the RAW label: the manager's copy is pre-escaped for use as a
     * key, and Vue escapes on render. An `Htmlable` label is flattened to a
     * string here (rendered as text, not markup) — the same trade the column
     * labels already make.
     *
     * @return array<string, array{label: string, wrapHeader: bool, alignment: ?string, hiddenFrom: ?string, visibleFrom: ?string}>
     */
    private function columnGroups(Table $table): array
    {
        $groups = [];

        foreach ($table->getColumnGroups() as $key => $group) {
            $alignment = $group->getAlignment();

            $groups[$key] = [
                'label' => (string) $group->getLabel(),
                'wrapHeader' => $group->canHeaderWrap(),
                'alignment' => $alignment instanceof Alignment ? $alignment->value : $alignment,
                'hiddenFrom' => $group->getHiddenFrom(),
                'visibleFrom' => $group->getVisibleFrom(),
            ];
        }

        return $groups;
    }

    private function columnType(Column $column): string
    {
        foreach ($this->columnSerializers as $serializer) {
            if ($serializer->canSerialize($column)) {
                return $serializer->type($column);
            }
        }

        return 'html';
    }

    /**
     * Selectable grouping config for the Vue settings control. `null` when the
     * table exposes no `groups()` — a forced-only `defaultGroup()` still groups
     * the records (headers render) but offers no UI to change it.
     *
     * The CURRENT selection is not here — it lives in the island's `state`
     * (inlined from `tableGrouping`, which stock's mount hook has already
     * seeded with a selectable `defaultGroup()`), because it is request state,
     * not schema, and the endpoint must be told it on every request.
     *
     * @return array{groups: list<array{id: string, label: string}>, directionSettingHidden: bool, settingsInDropdownOnDesktop: bool, collapsedByDefault: bool, settingsHidden: bool, triggerHtml: string}|null
     */
    private function grouping(Table $table): ?array
    {
        $groups = $table->getGroups();

        if ($groups === []) {
            return null;
        }

        $serializedGroups = [];

        foreach ($groups as $group) {
            $serializedGroups[] = [
                'id' => $group->getId(),
                'label' => (string) $group->getLabel(),
            ];
        }

        return [
            'groups' => $serializedGroups,
            'directionSettingHidden' => $table->isGroupingDirectionSettingHidden(),
            // Stock default (false): a mobile dropdown (`sm:fi-hidden`) PLUS
            // inline fields on desktop. True: the dropdown at every breakpoint.
            'settingsInDropdownOnDesktop' => $table->areGroupingSettingsInDropdownOnDesktop(),
            // Collapsible groups (per `Group::collapsible()`) start collapsed
            // when the table opts in; the client tracks collapse state by title.
            'collapsedByDefault' => $table->areGroupsCollapsedByDefault(),
            // Suppresses the settings selector entirely (stock: the toolbar
            // control is hidden). Grouping via `defaultGroup()` still applies —
            // records group and headers render, users just can't change it.
            'settingsHidden' => $table->areGroupingSettingsHidden(),
            // The dropdown trigger, rendered by Filament ITSELF so every
            // `groupRecordsTriggerAction()` customization survives (label,
            // icon, color, size, button-vs-iconButton, extraAttributes,
            // tooltip). Stock disables the action's own click handler
            // (`livewireClickHandlerEnabled(false)`) and toggles from the
            // wrapping `.fi-dropdown-trigger` — which is exactly where the
            // island binds its click, so the button needs no rewiring.
            'triggerHtml' => $table->getGroupRecordsTriggerAction()->toHtml(),
        ];
    }
}
