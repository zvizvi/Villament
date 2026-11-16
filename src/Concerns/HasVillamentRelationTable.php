<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Concerns;

use Closure;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Zvizvi\Villament\Schemas\VillamentIsland;
use Zvizvi\Villament\Support\RelationRegistry;
use Zvizvi\Villament\Support\ResourceRegistry;

/**
 * The island for a relationship table: apply it to a relation manager or to
 * a ManageRelatedRecords page. Everything around the table stays stock —
 * the relation manager tabs, the page's own relation managers, render hooks,
 * header actions — and the component keeps its full Livewire table state,
 * which the actions bridge and the native filters panel drive.
 *
 * A relation manager's island fetches from the relation endpoint, which
 * re-resolves the owner record through the resource's own route binding and
 * the manager through the page's own `getRelationManagers()` — the request
 * only ever names them.
 */
trait HasVillamentRelationTable
{
    use InteractsWithVillamentIsland;

    public function content(Schema $schema): Schema
    {
        $island = [
            $this->getTabsContentComponent(),
            RenderHook::make($this instanceof ManageRelatedRecords
                ? PanelsRenderHook::RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_BEFORE
                : PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE),
            VillamentIsland::make(fn (): array => $this->getVillamentTableConfig()),
            // Reordering is handed to the stock table, as on a List page.
            EmbeddedTable::make()->visible(fn (): bool => $this->isTableReordering()),
            RenderHook::make($this instanceof ManageRelatedRecords
                ? PanelsRenderHook::RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_AFTER
                : PanelsRenderHook::RESOURCE_RELATION_MANAGER_AFTER),
        ];

        if (! $this instanceof ManageRelatedRecords) {
            return $schema->components($island);
        }

        // Stock's ManageRelatedRecords layout: the table (hidden when it has
        // no columns), then the page's own relation managers.
        return $schema->components([
            Group::make($island)->visible(! empty($this->getTable()->getColumns())),
            $this->getRelationManagersContentComponent(),
        ]);
    }

    /**
     * A ManageRelatedRecords page keeps its table state in the URL (`#[Url]`,
     * as a List page does). A relation manager does not: it shares the URL
     * with its host page and that page's other managers.
     */
    protected function shouldSyncVillamentStateWithUrl(): bool
    {
        return $this instanceof ManageRelatedRecords;
    }

    /**
     * Authorized by the component itself — the relationship table's own
     * `canView()` / `canEdit()` / `canDelete()`, which go through the related
     * model's policy (or the related resource), exactly as its actions do.
     *
     * @return Closure(Model): array<string, bool>
     */
    protected function getVillamentRecordAbilities(): Closure
    {
        return fn (Model $record): array => [
            'view' => $this->canView($record),
            'edit' => $this->canEdit($record),
            'delete' => $this->canDelete($record),
        ];
    }

    protected function getVillamentEndpoint(): string
    {
        $panel = Filament::getCurrentOrDefaultPanel();
        $relations = app(RelationRegistry::class);

        $pageClass = $this->getPageClass();

        if (! method_exists($pageClass, 'getResource')) {
            throw new LogicException(sprintf(
                'The villament island on [%s] needs a resource page to host it; [%s] is not one.',
                static::class,
                $pageClass,
            ));
        }

        $resourceClass = $pageClass::getResource();
        $ownerRecord = $this->getOwnerRecord();

        $parameters = [
            'resource' => app(ResourceRegistry::class)->keyFor($panel, $resourceClass),
            'page' => $relations->pageKeyFor($resourceClass, $pageClass),
            // In the form the resource's own route binding takes.
            'owner' => filled($routeKeyName = $resourceClass::getRecordRouteKeyName())
                ? $ownerRecord->getAttribute($routeKeyName)
                : $ownerRecord->getRouteKey(),
        ];

        // A nested resource's record is resolved under its parent record, so
        // the endpoint is told which one — the record's inverse relationship,
        // which the page itself sets when it resolves the record.
        if ($parentRegistration = $resourceClass::getParentResourceRegistration()) {
            $parentResource = $parentRegistration->getParentResource();
            $parentRecord = $ownerRecord->{$parentRegistration->getInverseRelationshipName()};

            if ($parentRecord instanceof Model) {
                $parameters['parent'] = filled($parentRouteKeyName = $parentResource::getRecordRouteKeyName())
                    ? $parentRecord->getAttribute($parentRouteKeyName)
                    : $parentRecord->getRouteKey();
            }
        }

        if ($this instanceof RelationManager) {
            $parameters['relation'] = $relations->keyForComponent($this)
                ?? throw new LogicException(sprintf(
                    '[%s] is not among the relation managers [%s] shows for this record.',
                    static::class,
                    $pageClass,
                ));
        }

        if ($panel->hasTenancy()) {
            $parameters['tenant'] = Filament::getTenant();
        }

        return $panel->route(
            $this instanceof RelationManager ? 'villament.relation-data' : 'villament.related-data',
            $parameters,
        );
    }
}
