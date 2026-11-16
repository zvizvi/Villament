<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Concerns;

use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Zvizvi\Villament\Schemas\VillamentIsland;
use Zvizvi\Villament\Support\ResourceRegistry;

/**
 * Swaps the embedded Livewire table on a ListRecords page for the Vue island
 * mount point. Header actions, widgets, tabs, breadcrumbs and render hooks
 * stay untouched Filament output, and the Livewire page component keeps its
 * full table state (needed for the actions bridge and the native filters panel).
 */
trait HasVillamentTable
{
    use InteractsWithVillamentIsland;

    public function content(Schema $schema): Schema
    {
        // The config is resolved at render time, not here: since Filament 5.10
        // the page's schemas are cached during Livewire hydration, before
        // `bootedInteractsWithTable()` has built the table.
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                VillamentIsland::make(fn (): array => $this->getVillamentTableConfig()),
                // Reordering records is handed to the stock table: while it is
                // on, the island is hidden (never removed — see the island
                // view) and Filament's own table, with its drag handles and
                // `reorderTable()`, takes its place until "done" is pressed.
                EmbeddedTable::make()->visible(fn (): bool => $this->isTableReordering()),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    protected function getVillamentEndpoint(): string
    {
        $resourceClass = static::getResource();
        $panel = Filament::getCurrentOrDefaultPanel();

        $endpointParameters = ['resource' => app(ResourceRegistry::class)->keyFor($panel, $resourceClass)];

        // A nested resource's list is ONE parent's records: the endpoint has
        // to be told which parent, in the form Filament's own route binding
        // takes (the parent resource's route key).
        if (($parentRegistration = $resourceClass::getParentResourceRegistration()) && $this->parentRecord) {
            $parentResource = $parentRegistration->getParentResource();

            $endpointParameters['parent'] = filled($routeKeyName = $parentResource::getRecordRouteKeyName())
                ? $this->parentRecord->getAttribute($routeKeyName)
                : $this->parentRecord->getRouteKey();
        }

        // Tenant panels route the endpoint under `{tenant}` — include the
        // current tenant so the generated URL resolves (and `IdentifyTenant`
        // can re-establish it on the AJAX request).
        if ($panel->hasTenancy()) {
            $endpointParameters['tenant'] = Filament::getTenant();
        }

        return $panel->route('villament.data', $endpointParameters);
    }

    /**
     * @return class-string<\Filament\Resources\Resource>
     */
    protected function getVillamentRecordAbilities(): string
    {
        return static::getResource();
    }
}
