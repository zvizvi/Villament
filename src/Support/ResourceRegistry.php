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
     * The endpoint's name for a resource. NOT its slug: a slug is unique only
     * among siblings, so a nested resource (`CampaignResource` > donations)
     * and a top-level one can both be `donations`. The route base name
     * carries the parent chain and the cluster (`resources.donations` vs
     * `resources.campaigns.donations`) and is unique in the panel by
     * construction — Filament's own routes are named from it.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resourceClass
     */
    public function keyFor(Panel $panel, string $resourceClass): string
    {
        // The `filament.{panel}.` prefix says nothing the URL does not already
        // say (the endpoint is routed per panel), so it is left out.
        return (string) str($resourceClass::getRouteBaseName($panel))
            ->after("filament.{$panel->getId()}.");
    }

    /**
     * @return class-string<\Filament\Resources\Resource>|null
     */
    public function resolve(Panel $panel, string $key): ?string
    {
        $resources = $panel->getResources();

        foreach ($resources as $resourceClass) {
            if ($this->keyFor($panel, $resourceClass) === $key) {
                return $resourceClass;
            }
        }

        // A bare slug is what an island rendered before keys existed still
        // sends (a page left open across a deploy). It can only mean a
        // top-level resource — a nested one never worked through it.
        foreach ($resources as $resourceClass) {
            if (
                $resourceClass::getParentResourceRegistration() === null
                && $resourceClass::getSlug($panel) === $key
            ) {
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
