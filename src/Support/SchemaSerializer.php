<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Support;

use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;

/**
 * Serializes a Filament Table definition into the JSON schema payload the Vue
 * island consumes. Runs once per page load; the hash of its output doubles as
 * the drift-detection `schemaVersion`.
 */
final class SchemaSerializer
{
    /**
     * @var list<FilterSerializers\FilterSerializer>
     */
    private readonly array $filterSerializers;

    /**
     * @param  list<FilterSerializers\FilterSerializer>|null  $filterSerializers
     */
    public function __construct(?array $filterSerializers = null)
    {
        $this->filterSerializers = $filterSerializers ?? [
            new FilterSerializers\TernaryFilterSerializer,
            new FilterSerializers\SelectFilterSerializer,
            new FilterSerializers\FallbackFilterSerializer,
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

        $filters = [];

        foreach ($table->getFilters() as $filter) {
            foreach ($this->filterSerializers as $serializer) {
                if ($serializer->canSerialize($filter)) {
                    $filters[] = $serializer->serialize($filter);

                    break;
                }
            }
        }

        return [
            'columns' => $columns,
            'defaultSort' => $defaultSortColumn === null ? null : [
                'column' => $defaultSortColumn,
                'direction' => $table->getDefaultSortDirection() ?? 'asc',
            ],
            'globallySearchable' => $table->isSearchable(),
            'filters' => $filters,
            'pagination' => [
                'mode' => match ($table->getPaginationMode()) {
                    PaginationMode::Simple => 'simple',
                    PaginationMode::Cursor => 'cursor',
                    default => 'offset',
                },
                'pageOptions' => $table->getPaginationPageOptions(),
                'defaultPerPage' => $table->getDefaultPaginationPageOption(),
            ],
            'strings' => $this->strings($table),
        ];
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
            'filtersHeading' => __('filament-tables::table.filters.heading'),
            'filtersIndicator' => __('filament-tables::table.filters.indicator'),
            'filtersApply' => __('filament-tables::table.filters.actions.apply.label'),
            'filtersRemove' => __('filament-tables::table.filters.actions.remove.label'),
            'filtersRemoveAll' => __('filament-tables::table.filters.actions.remove_all.label'),
            'filtersReset' => __('filament-tables::table.filters.actions.reset.label'),
            'selectPlaceholder' => __('filament-tables::table.filters.select.placeholder'),
            'multiSelectPlaceholder' => __('filament-tables::table.filters.multi_select.placeholder'),
            'emptyHeading' => __('filament-tables::table.empty.heading', ['model' => $table->getPluralModelLabel()]),
            'paginationLabel' => __('filament::components/pagination.label'),
            'paginationOverview' => __('filament::components/pagination.overview'),
            'perPageLabel' => __('filament::components/pagination.fields.records_per_page.label'),
            'perPageAll' => __('filament::components/pagination.fields.records_per_page.options.all'),
            'previousLabel' => __('filament::components/pagination.actions.previous.label'),
            'nextLabel' => __('filament::components/pagination.actions.next.label'),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function version(array $schema): string
    {
        return hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeColumn(Column $column): array
    {
        $alignment = $column->getAlignment();

        return [
            'key' => $column->getName(),
            'label' => (string) $column->getLabel(),
            'type' => $this->columnType($column),
            'sortable' => $column->isSortable(),
            'individuallySearchable' => $column->isIndividuallySearchable(),
            'toggleable' => $column->isToggleable(),
            'hiddenByDefault' => $column->isToggleable() && $column->isToggledHiddenByDefault(),
            'alignment' => $alignment instanceof Alignment ? $alignment->value : $alignment,
        ];
    }

    private function columnType(Column $column): string
    {
        return match (true) {
            $column instanceof ViewColumn => 'html',
            $column instanceof IconColumn => 'icon',
            $column instanceof ImageColumn => 'image',
            $column instanceof TextColumn => $column->isBadge() ? 'badge' : 'text',
            default => 'html',
        };
    }
}
