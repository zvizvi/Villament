<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Support\FilterSerializers;

use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\TernaryFilter;

/**
 * TernaryFilter extends SelectFilter, so this serializer must sit BEFORE the
 * select serializer in the chain.
 */
final class TernaryFilterSerializer implements FilterSerializer
{
    public function canSerialize(BaseFilter $filter): bool
    {
        return $filter instanceof TernaryFilter;
    }

    public function serialize(BaseFilter $filter): array
    {
        /** @var TernaryFilter $filter */
        return [
            'key' => $filter->getName(),
            'label' => (string) $filter->getLabel(),
            'type' => 'ternary',
            'trueLabel' => $filter->getTrueLabel(),
            'falseLabel' => $filter->getFalseLabel(),
            'placeholder' => $filter->getPlaceholder(),
        ];
    }
}
