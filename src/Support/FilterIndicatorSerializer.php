<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

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

        // Per-column search indicators come from the HasTable contract method;
        // the column name is only carried inside the indicator's Livewire
        // remove handler ("resetTableColumnSearch('name')") — parse it out for
        // the client-side removal routing.
        foreach ($table->getLivewire()->getTableColumnSearchIndicators() as $indicator) {
            $column = null;

            if (preg_match(
                "/resetTableColumnSearch\('([^']+)'\)/",
                (string) $indicator->getRemoveLivewireClickHandler(),
                $matches,
            ) === 1) {
                $column = $matches[1];
            }

            $indicators[] = [
                'type' => 'columnSearch',
                'filter' => null,
                'field' => null,
                'column' => $column,
                'label' => (string) $indicator->getLabel(),
                'removable' => $indicator->isRemovable(),
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
