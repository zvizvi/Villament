<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Panel;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Zvizvi\Villament\Concerns\HasVillamentTable;

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

    /**
     * The List page class the harness should drive. Prefers the page carrying
     * HasVillamentTable over the plain `index` page: Filament keys its session
     * persistence (column manager, etc.) by component class, so the harness
     * must be the SAME class as the island's host page to read that state —
     * and it also inherits any page-level `table()` customizations.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resourceClass
     * @return class-string<ListRecords>|null
     */
    public function resolveListPage(string $resourceClass): ?string
    {
        $pages = $resourceClass::getPages();

        foreach ($pages as $registration) {
            $pageClass = $registration->getPage();

            if (
                is_subclass_of($pageClass, ListRecords::class)
                && in_array(HasVillamentTable::class, class_uses_recursive($pageClass), true)
            ) {
                return $pageClass;
            }
        }

        $indexPage = isset($pages['index']) ? $pages['index']->getPage() : null;

        return ($indexPage !== null && is_subclass_of($indexPage, ListRecords::class)) ? $indexPage : null;
    }
}
