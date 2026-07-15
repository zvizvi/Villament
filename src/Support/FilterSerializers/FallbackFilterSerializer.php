<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Support\FilterSerializers;

use Filament\Tables\Filters\BaseFilter;

/**
 * Terminal link of the chain: any unrecognized filter is marked
 * `livewire-fallback`, and the Vue UI drives the page's original (hidden)
 * Livewire filter form for it instead of rebuilding it client-side.
 */
final class FallbackFilterSerializer implements FilterSerializer
{
    public function canSerialize(BaseFilter $filter): bool
    {
        return true;
    }

    public function serialize(BaseFilter $filter): array
    {
        return [
            'key' => $filter->getName(),
            'label' => (string) $filter->getLabel(),
            'type' => 'livewire-fallback',
        ];
    }
}
