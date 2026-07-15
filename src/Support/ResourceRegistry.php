<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Support;

use Filament\Panel;
use Filament\Resources\Resource;

/**
 * Resolves a panel resource class from its URL slug. Only resources actually
 * registered on the panel are resolvable — unknown slugs mean 404, never a
 * class lookup from user input.
 */
final class ResourceRegistry
{
    /**
     * @return class-string<\Filament\Resources\Resource>|null
     */
    public function resolve(Panel $panel, string $slug): ?string
    {
        foreach ($panel->getResources() as $resourceClass) {
            if ($resourceClass::getSlug($panel) === $slug) {
                return $resourceClass;
            }
        }

        return null;
    }
}
