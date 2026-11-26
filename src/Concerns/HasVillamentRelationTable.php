<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Concerns;

use Closure;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LogicException;
use Zvizvi\Villament\Schemas\VillamentIsland;
use Zvizvi\Villament\Support\RelationTableToken;

/**
 * The island for a relationship table: apply it to a relation manager or to
 * a ManageRelatedRecords page. Everything around the table stays stock —
 * the relation manager tabs, the page's own relation managers, render hooks,
 * header actions — and the component keeps its full Livewire table state,
 * which the actions bridge and the native filters panel drive.
 *
 * A relation manager's island fetches from the relation endpoint with a
 * {@see RelationTableToken} naming it, issued here when the island renders.
 * The endpoint re-resolves the owner record through the page and re-runs
 * every access check on each request; the token only spares it working out
 * again which table this is.
 */
trait HasVillamentRelationTable
{
    use InteractsWithVillamentIsland;

    /**
     * True only in the request that mounted the component (a protected
     * property is not carried between requests): the one moment the URL's
     * state is applied to a relation manager.
     */
    protected bool $isVillamentMounting = false;

    public function mountHasVillamentRelationTable(): void
    {
        $this->isVillamentMounting = true;
    }

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
     * as a List page does). A relation manager does not, as in stock, unless
     * `villament.relation_manager_url_state` turns it on - and then under a
     * key of its own ({@see getVillamentUrlNamespace()}), since it shares the
     * URL with its host page and that page's other managers.
     */
    protected function shouldSyncVillamentStateWithUrl(): bool
    {
        return $this instanceof ManageRelatedRecords
            || (bool) config('villament.relation_manager_url_state', false);
    }

    /**
     * A relation manager's parameters are nested under its relationship's
     * name (`products[sort]`, `products[page]`). Two managers of one
     * relationship drawn on the same page at once need a name each.
     */
    protected function getVillamentUrlNamespace(): ?string
    {
        return $this instanceof ManageRelatedRecords ? null : static::getRelationshipName();
    }

    /**
     * A relation manager reads its state once, as it mounts, from under its
     * own key. It usually mounts in a Livewire request (a lazy load, or the
     * request that opened its tab), where the page's query string is the
     * referer's - the place Livewire itself reads `#[Url]` properties from
     * there.
     */
    protected function getVillamentUrlStateRequest(): ?Request
    {
        $namespace = $this->getVillamentUrlNamespace();

        if ($namespace === null) {
            $request = request();

            return ($request->isMethod('GET') && $request->query() !== []) ? $request : null;
        }

        if (! $this->isVillamentMounting) {
            return null;
        }

        $query = request()->query();

        if (app('livewire')->isLivewireRequest()) {
            $query = [];
            parse_str((string) parse_url((string) request()->header('Referer'), PHP_URL_QUERY), $query);
        }

        $state = $query[$namespace] ?? null;

        if (! is_array($state) || $state === []) {
            return null;
        }

        return Request::create('/', 'GET', $state);
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
        $tokens = app(RelationTableToken::class);

        // A nested resource's record is resolved under its parent record —
        // the record's inverse relationship, which the page itself sets when
        // it resolves the record.
        $parentKey = null;

        if ($parentRegistration = $resourceClass::getParentResourceRegistration()) {
            $parentRecord = $ownerRecord->{$parentRegistration->getInverseRelationshipName()};

            if ($parentRecord instanceof Model) {
                $parentKey = $this->getVillamentRouteKey($parentRegistration->getParentResource(), $parentRecord);
            }
        }

        $parameters = [
            'token' => $tokens->issue(
                panelId: $panel->getId(),
                resourceClass: $resourceClass,
                pageClass: $pageClass,
                relationManagerClass: $this instanceof RelationManager ? $this::class : null,
                properties: $this instanceof RelationManager ? $tokens->mountedProperties($this) : [],
                ownerKey: $this->getVillamentRouteKey($resourceClass, $ownerRecord),
                parentKey: $parentKey,
                tenantKey: $this->getVillamentTenantKey(),
            ),
        ];

        if ($panel->hasTenancy()) {
            $parameters['tenant'] = Filament::getTenant();
        }

        return $panel->route('villament.relation-data', $parameters);
    }

    private function getVillamentTenantKey(): int|string|null
    {
        $key = Filament::getTenant()?->getKey();

        return (is_int($key) || is_string($key)) ? $key : null;
    }

    /**
     * A record's key in the form its resource's own route binding takes.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resourceClass
     */
    private function getVillamentRouteKey(string $resourceClass, Model $record): int|string
    {
        $key = filled($routeKeyName = $resourceClass::getRecordRouteKeyName())
            ? $record->getAttribute($routeKeyName)
            : $record->getRouteKey();

        if (! (is_int($key) || is_string($key))) {
            throw new LogicException(sprintf('[%s] has no usable route key for the villament endpoint.', $record::class));
        }

        return $key;
    }
}
