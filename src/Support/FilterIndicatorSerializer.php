<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Support;

use Filament\Support\View\Components\BadgeComponent;
use Filament\Tables\Table;

use function Filament\Support\get_component_color_classes;

/**
 * Serializes the active filter indicator chips. Indicators are evaluated by
 * Filament itself (`indicateUsing` closures included) against the injected
 * table state, so custom indicator labels match the stock UI exactly.
 */
final class FilterIndicatorSerializer
{
    /**
     * @return list<array<string, mixed>>
     */
    public function serialize(Table $table): array
    {
        $indicators = [];

        // Stock composition (Table::getFilterIndicators()): the global search
        // indicator first, then per-column search indicators, then filters.
        if ($table->hasSearch()) {
            $indicators[] = [
                'type' => 'search',
                'filter' => null,
                'field' => null,
                'label' => (string) $table->getSearchIndicator()->getLabel(),
                'removable' => true,
                'colorClasses' => implode(' ', get_component_color_classes(BadgeComponent::class, 'primary')),
            ];
        }

        $columnSearches = $table->getLivewire()->getTableColumnSearches();

        foreach ($table->getColumns() as $column) {
            if ($column->isHidden() || ! $column->isIndividuallySearchable()) {
                continue;
            }

            $search = data_get($columnSearches, $column->getName());

            if (blank($search)) {
                continue;
            }

            $indicators[] = [
                'type' => 'columnSearch',
                'filter' => null,
                'field' => null,
                'column' => $column->getName(),
                'label' => "{$column->getLabel()}: {$search}",
                'removable' => true,
                'colorClasses' => implode(' ', get_component_color_classes(BadgeComponent::class, 'primary')),
            ];
        }

        foreach ($table->getFilters() as $filter) {
            foreach ($filter->getIndicators() as $indicator) {
                $color = $indicator->getColor();
                $color = is_string($color) ? $color : 'primary';

                $indicators[] = [
                    'type' => 'filter',
                    'filter' => $filter->getName(),
                    'field' => $indicator->getRemoveField(),
                    'label' => (string) $indicator->getLabel(),
                    'removable' => $indicator->isRemovable(),
                    'colorClasses' => implode(' ', get_component_color_classes(BadgeComponent::class, $color)),
                ];
            }
        }

        return $indicators;
    }
}
