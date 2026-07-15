<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Support\FilterSerializers;

use Filament\Tables\Filters\BaseFilter;

/**
 * One link in the filter serializer chain. The first serializer whose
 * `canSerialize()` returns true wins; the chain always ends with the
 * fallback serializer, so no filter can ever block adoption.
 */
interface FilterSerializer
{
    public function canSerialize(BaseFilter $filter): bool;

    /**
     * @return array<string, mixed>
     */
    public function serialize(BaseFilter $filter): array;
}
