<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Icons\Heroicon;
use Filament\Support\View\SupportIconAlias;
use Filament\Tables\Columns\Column;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Filament\Tables\View\TablesIconAlias;
use Illuminate\Contracts\Support\Htmlable;

use function Filament\Support\generate_icon_html;

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
     * @param  int|null  $debounceMs  A package-level override; `null` (the
     *                                default) follows the table's own
     *                                `searchDebounce()`.
     */
    public function __construct(
        ?array $columnSerializers = null,
        private readonly ?int $debounceMs = null,
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
            // Stock searches on blur instead of as-you-type when asked; the
            // island then skips its debounce entirely, like Livewire does.
            'searchOnBlur' => $table->isSearchOnBlur(),
            'striped' => $table->isStriped(),
            // The column manager — trigger, dropdown/modal and panel — is
            // Filament-native too, teleported into a toolbar slot. The island
            // only needs to know WHETHER to render that slot; everything the
            // manager does (layout, reset position, grid columns, a customized
            // trigger, drag reordering) stays Filament's.
            'hasColumnManager' => $table->hasColumnManager(),
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
            'selection' => $this->selection($table),
            'header' => $this->header($table),
            'recordActions' => $this->recordActions($table),
            'debounceMs' => $this->debounceMs ?? $this->searchDebounceMs($table),
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
            'badgeDelete' => $this->iconHtml(Heroicon::XMark, SupportIconAlias::BADGE_DELETE_BUTTON, IconSize::ExtraSmall),
            'group' => $this->iconHtml(Heroicon::RectangleStack, TablesIconAlias::ACTIONS_GROUP),
            'groupCollapse' => $this->iconHtml(Heroicon::ChevronUp, TablesIconAlias::GROUPING_COLLAPSE_BUTTON, IconSize::Small),
        ];
    }

    /**
     * The table's own header block — `heading()`, `description()` and the
     * `headerActions()` position. The actions themselves live in
     * `actions.header`; this is the chrome around them.
     *
     * `header()` (a fully custom Blade view replacing the whole block) is NOT
     * carried: it is arbitrary developer markup, potentially with its own
     * Livewire/Alpine bindings, which the island cannot render faithfully.
     *
     * @return array{heading: ?string, description: ?string, actionsPosition: string}
     */
    private function header(Table $table): array
    {
        return [
            'heading' => $this->stringable($table->getHeading()),
            'description' => $this->stringable($table->getDescription()),
            // Adaptive (the default) puts the actions beside the heading on
            // desktop and below it on mobile; Bottom always puts them below.
            'actionsPosition' => $table->getHeaderActionsPosition()->name,
        ];
    }

    /**
     * Where and how the record-actions cell renders: `recordActions(position:)`,
     * `recordActionsAlignment()` and `recordActionsColumnLabel()`.
     *
     * `AfterContent` only exists for the content/grid layouts, which the island
     * does not implement — it is reported as `AfterCells`, the table-layout
     * equivalent (and what `getRecordActionsPosition()` itself returns for a
     * columns-layout table).
     *
     * @return array{position: string, alignmentClass: ?string, columnLabel: ?string}
     */
    private function recordActions(Table $table): array
    {
        $position = $table->getRecordActionsPosition();

        return [
            'position' => match ($position) {
                RecordActionsPosition::BeforeCells => 'BeforeCells',
                RecordActionsPosition::BeforeColumns => 'BeforeColumns',
                RecordActionsPosition::AfterColumns => 'AfterColumns',
                default => 'AfterCells',
            },
            // Stock's own match: End/Right (and anything unknown) get NO class.
            'alignmentClass' => $this->alignmentClass($table->getRecordActionsAlignment()),
            'columnLabel' => $this->stringable($table->getRecordActionsColumnLabel()),
        ];
    }

    private function alignmentClass(?string $alignment): ?string
    {
        return match ($alignment) {
            Alignment::Center->value => 'fi-align-center',
            Alignment::Start->value, Alignment::Left->value => 'fi-align-start',
            Alignment::Between->value, Alignment::Justify->value => 'fi-align-between',
            Alignment::End->value, Alignment::Right->value, null => null,
            default => $alignment,
        };
    }

    /**
     * Record-selection config. `enabled` is Filament's OWN answer
     * (`Table::isSelectionEnabled()`), not "are there bulk actions" — that is
     * only its fallback, and `selectable()` / `selectable(false)` override it
     * in both directions.
     *
     * @return array{enabled: bool, disabled: bool, maxRecords: ?int, currentPageOnly: bool, groupsOnly: bool, canTrackDeselected: bool, checksIfRecordIsSelectable: bool}
     */
    private function selection(Table $table): array
    {
        return [
            'enabled' => $table->isSelectionEnabled(),
            // `disabledSelection()`: the checkboxes render, but disabled, and
            // the indicator's action links disappear.
            'disabled' => $table->isSelectionDisabled(),
            // `1` makes the checkboxes a radio group (no page checkbox, no
            // selection indicator); anything else caps the total.
            'maxRecords' => $table->getMaxSelectableRecords(),
            'currentPageOnly' => $table->selectsCurrentPageOnly(),
            // `selectGroupsOnly()`: only the group headers select; the page
            // checkbox and the "select all" link go away.
            'groupsOnly' => $table->selectsGroupsOnly(),
            // Whether "select all" may use inversion tracking rather than
            // materializing every key.
            'canTrackDeselected' => $table->canTrackDeselectedRecords(),
            // When true, each record carries its own `selectable` flag.
            'checksIfRecordIsSelectable' => $table->checksIfRecordIsSelectable(),
        ];
    }

    /**
     * `searchDebounce()` speaks Livewire's `.debounce` syntax (`'500ms'`,
     * `'1s'`), which the island has to turn back into a number. Filament's own
     * default is 500ms, so that is what an unconfigured table gets here too.
     */
    private function searchDebounceMs(Table $table): int
    {
        $debounce = trim($table->getSearchDebounce());

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(ms|s)?$/i', $debounce, $matches) !== 1) {
            return 500;
        }

        $value = (float) $matches[1];

        return (int) round(strtolower($matches[2] ?? 'ms') === 's' ? $value * 1000 : $value);
    }

    private function iconHtml(string|\BackedEnum $icon, ?string $alias = null, ?IconSize $size = null): ?string
    {
        return generate_icon_html($icon, $alias, size: $size)?->toHtml();
    }

    private function stringable(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof Htmlable ? $value->toHtml() : (string) $value;
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
            // `searchPlaceholder()` overrides the translated default, exactly
            // as it does in the stock search field.
            'searchPlaceholder' => $table->getSearchPlaceholder() ?? __('filament-tables::table.fields.search.placeholder'),
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
            'bulkSelectPage' => __('filament-tables::table.fields.bulk_select_page.label'),
            'bulkSelectRecord' => __('filament-tables::table.fields.bulk_select_record.label'),
            'bulkSelectGroup' => __('filament-tables::table.fields.bulk_select_group.label'),
            'selectedCount' => __('filament-tables::table.selection_indicator.selected_count'),
            'selectAll' => __('filament-tables::table.selection_indicator.actions.select_all.label'),
            'deselectAll' => __('filament-tables::table.selection_indicator.actions.deselect_all.label'),
            // The record-actions header cell's accessible name, pluralized on
            // the number of actions exactly as stock's `trans_choice()` is.
            'recordActionsHeading' => trans_choice(
                'filament-tables::table.columns.actions.label',
                count($table->getFlatRecordActions()),
            ),
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
        $headerTooltip = $column->getHeaderTooltip();

        return [
            'key' => $column->getName(),
            'label' => (string) $column->getLabel(),
            'headerTooltip' => $headerTooltip instanceof Htmlable ? $headerTooltip->toHtml() : $headerTooltip,
            // Developer-authored `extraHeaderAttributes()`. Per column, not per
            // record — the header renders once.
            'extraHeaderAttributes' => $column->getExtraHeaderAttributes(),
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
