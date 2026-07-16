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
     * @return list<array{filter: string, field: string|null, label: string, removable: bool}>
     */
    public function serialize(Table $table): array
    {
        $indicators = [];

        foreach ($table->getFilters() as $filter) {
            foreach ($filter->getIndicators() as $indicator) {
                $color = $indicator->getColor();
                $color = is_string($color) ? $color : 'primary';

                $indicators[] = [
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
