<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Http\Controllers;

use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Livewire\LivewireManager;
use Zvizvi\Villament\Http\HeadlessTableResponder;
use Zvizvi\Villament\Support\HeadlessTableHarness;
use Zvizvi\Villament\Support\ParentRouteParameters;
use Zvizvi\Villament\Support\RelationRegistry;
use Zvizvi\Villament\Support\ResourceRegistry;

/**
 * The data endpoint of a relationship table's island, in two shapes:
 *
 *     GET /villament/{resource}/pages/edit/relations/{relation}/data?owner=42
 *     GET /villament/{resource}/pages/{page}/data?owner=42
 *
 * the first for a relation manager on one of the resource's record pages,
 * the second for a ManageRelatedRecords page. Nothing in the request is ever
 * trusted as a class or a record: the page is looked up among the resource's
 * own pages, the owner record through that page's own `resolveRecord()` (the
 * resource's route binding, scoped to the parent record of a nested
 * resource), and the relation manager among the ones the page itself shows
 * for that record. Access is checked as stock checks it on every Livewire
 * request — the page's `canAccess()` for the record, and the manager's
 * `canViewForRecord()` (Filament's own `bootCanAuthorizeAccess()`, replayed
 * by the harness).
 */
final class RelationTableDataController
{
    public function __construct(
        private readonly ResourceRegistry $resources,
        private readonly RelationRegistry $relations,
        private readonly HeadlessTableHarness $harness,
        private readonly HeadlessTableResponder $responder,
        private readonly LivewireManager $livewire,
        private readonly ParentRouteParameters $parentRouteParameters,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $panel = Filament::getCurrentPanel();
        abort_if($panel === null, 404);

        // Named route parameters, never positional ones: on tenant panels the
        // route also carries `{tenant}`.
        $resource = $request->route('resource');
        abort_unless(is_string($resource), 404);

        $resourceClass = $this->resources->resolve($panel, $resource);
        abort_if($resourceClass === null, 404);

        $relation = $request->route('relation');

        // The host page of a relation manager, or the ManageRelatedRecords
        // page itself.
        $pageKey = $request->route('page');
        abort_unless(is_string($pageKey) && $pageKey !== '', 404);

        $pageClass = is_string($relation)
            ? $this->relations->resolvePage($resourceClass, $pageKey)
            : $this->relations->resolveRelatedRecordsPage($resourceClass, $pageKey);
        abort_if($pageClass === null, 404);

        $ownerRecord = $this->resolveOwnerRecord($request, $resourceClass, $pageClass);

        if (is_string($relation)) {
            $manager = $this->relations->resolveRelationManager($pageClass, $ownerRecord, $relation);
            abort_if($manager === null, 404);

            $this->harness->forRelationManager($manager['class'], $ownerRecord, $pageClass, $manager['properties']);
        } else {
            /** @var class-string<ManageRelatedRecords> $pageClass */
            $this->harness->forRelatedRecordsPage($pageClass, $ownerRecord);
        }

        return $this->responder->respond($request, $this->harness);
    }

    /**
     * The owner record, exactly as the host page would resolve and authorize
     * it on a Livewire request: `resolveRecord()` (the resource's route
     * binding, and for a nested resource `mountParentRecord()` + scoping to
     * the parent), then `authorizeResourceAccess()` and
     * `canAccess(['record' => …])` — the page's `hydrate` checks.
     *
     * @param  class-string<resource>  $resourceClass
     * @param  class-string<Page>  $pageClass
     */
    private function resolveOwnerRecord(Request $request, string $resourceClass, string $pageClass): Model
    {
        $ownerKey = $request->query('owner');
        abort_unless(is_string($ownerKey) && $ownerKey !== '', 404);

        $page = $this->livewire->new($pageClass);
        abort_unless($page instanceof Page, 404);

        // A nested resource's record lives under one parent record, named by
        // `parent` and resolved through the PARENT resource's own binding, as
        // on the List endpoint. `mountParentRecord()` (inside
        // `resolveRecord()`) then re-resolves it and authorizes the chain.
        if ($parentRegistration = $resourceClass::getParentResourceRegistration()) {
            $parentKey = $request->query('parent');
            abort_unless(is_string($parentKey) && $parentKey !== '', 404);

            $parentRecord = $parentRegistration->getParentResource()::resolveRecordRouteBinding($parentKey);
            abort_if($parentRecord === null, 404);

            $page->parentRecord = $parentRecord;

            $route = $request->route();

            if ($route instanceof Route) {
                $this->parentRouteParameters->fill($route, $resourceClass, $parentRecord);
            }
        }

        /** @var Model $ownerRecord */
        $ownerRecord = (fn (): Model => $this->resolveRecord($ownerKey))->call($page);

        $pageClass::authorizeResourceAccess();
        abort_unless($pageClass::canAccess(['record' => $ownerRecord]), 403);

        return $ownerRecord;
    }
}
