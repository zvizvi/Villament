<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Support\FilterSerializers;

use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\SelectFilter;

final class SelectFilterSerializer implements FilterSerializer
{
    public function canSerialize(BaseFilter $filter): bool
    {
        if (! $filter instanceof SelectFilter) {
            return false;
        }

        // Relationship/searchable selects resolve options lazily (possibly via
        // async search); when no static options are resolvable the filter
        // falls through to the livewire-fallback serializer.
        return $filter->getOptions() !== [];
    }

    public function serialize(BaseFilter $filter): array
    {
        /** @var SelectFilter $filter */
        return [
            'key' => $filter->getName(),
            'label' => (string) $filter->getLabel(),
            'type' => 'select',
            'options' => $filter->getOptions(),
            'multiple' => $filter->isMultiple(),
            'placeholder' => $filter->getPlaceholder(),
        ];
    }
}
