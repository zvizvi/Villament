<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Http\Controllers;

use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Livewire\LivewireManager;
use Zvizvi\Villament\Concerns\HasVillamentRelationTable;
use Zvizvi\Villament\Http\HeadlessTableResponder;
use Zvizvi\Villament\Support\HeadlessTableHarness;
use Zvizvi\Villament\Support\ParentRouteParameters;
use Zvizvi\Villament\Support\RelationTableToken;

/**
 * The data endpoint of a relationship table's island — a relation manager on
 * one of a resource's record pages, or a ManageRelatedRecords page:
 *
 *     GET /villament/relation-data?token=…
 *
 * The token ({@see RelationTableToken}) is encrypted and authenticated, so
 * what it names was named by the server; it is still checked against the
 * panel (its resources, their pages, the trait opt-in) before anything is
 * instantiated. Authorization is NOT taken from it: the owner record is
 * resolved through the page's own `resolveRecord()` (the resource's route
 * binding, scoped to the parent record of a nested resource), and the
 * page's and the manager's own access checks run on every request — the
 * page's `authorizeResourceAccess()` / `canAccess()`, and the manager's
 * `canViewForRecord()` (Filament's own `bootCanAuthorizeAccess()`, replayed
 * by the harness).
 */
final class RelationTableDataController
{
    public function __construct(
        private readonly RelationTableToken $tokens,
        private readonly HeadlessTableHarness $harness,
        private readonly HeadlessTableResponder $responder,
        private readonly LivewireManager $livewire,
        private readonly ParentRouteParameters $parentRouteParameters,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $panel = Filament::getCurrentPanel();
        abort_if($panel === null, 404);

        $token = $request->query('token');
        abort_unless(is_string($token) && $token !== '', 404);

        $target = $this->tokens->read($token);
        abort_if($target === null, 404);

        // Sealed for this panel, for one of its resources and one of that
        // resource's pages.
        abort_unless($target['panel'] === $panel->getId(), 404);

        // Rendered under this tenant (set by `IdentifyTenant` from the
        // route), compared as strings: a key may come back as int or string.
        $tenantKey = Filament::getTenant()?->getKey();
        abort_unless(
            ($target['tenant'] === null && $tenantKey === null)
                || ($target['tenant'] !== null && is_scalar($tenantKey) && (string) $target['tenant'] === (string) $tenantKey),
            404,
        );

        $resourceClass = $target['resource'];
        abort_unless(is_subclass_of($resourceClass, Resource::class) && in_array($resourceClass, $panel->getResources(), true), 404);

        $pageClass = $target['page'];
        $pageClasses = array_map(
            fn (PageRegistration $registration): string => $registration->getPage(),
            array_values($resourceClass::getPages()),
        );

        if (! in_array($pageClass, $pageClasses, true) || ! is_subclass_of($pageClass, Page::class)) {
            abort(404);
        }

        $managerClass = $target['manager'];

        if ($managerClass !== null) {
            abort_unless(is_subclass_of($managerClass, RelationManager::class) && $this->optedIn($managerClass), 404);
        } else {
            abort_unless(is_subclass_of($pageClass, ManageRelatedRecords::class) && $this->optedIn($pageClass), 404);
        }

        $ownerRecord = $this->resolveOwnerRecord($request, $resourceClass, $pageClass, $target['owner'], $target['parent']);

        $this->giveTheRouteTheOwnersRecordParameter($request, $target['owner']);

        if ($managerClass !== null) {
            /** @var class-string<RelationManager> $managerClass */
            $this->harness->forRelationManager($managerClass, $ownerRecord, $pageClass, $target['properties']);
        } else {
            /** @var class-string<ManageRelatedRecords> $pageClass */
            $this->harness->forRelatedRecordsPage($pageClass, $ownerRecord);
        }

        return $this->responder->respond($request, $this->harness);
    }

    /**
     * The host page's route carries the owner as `{record}`, and Filament
     * reads it from there: a related resource NESTED under the owner's
     * resource (`/campaigns/{campaign}/groups`) builds its create, edit, view
     * and record URLs by guessing the missing `{campaign}` from the current
     * route's `{record}` (`Resource::getUrl(shouldGuessMissingParameters:
     * true)`). The endpoint's route has no `{record}`, so the first such URL
     * threw. The owner was resolved and authorized above, under the key the
     * token carries, which is exactly what the page's `{record}` holds.
     */
    private function giveTheRouteTheOwnersRecordParameter(Request $request, int|string $ownerKey): void
    {
        $route = $request->route();

        if ($route instanceof Route && ! $route->hasParameter('record')) {
            $route->setParameter('record', $ownerKey);
        }
    }

    private function optedIn(string $class): bool
    {
        return in_array(HasVillamentRelationTable::class, class_uses_recursive($class), true);
    }

    /**
     * The owner record, exactly as the host page would resolve and authorize
     * it on a Livewire request: `resolveRecord()` (the resource's route
     * binding, and for a nested resource `mountParentRecord()` + scoping to
     * the parent), then `authorizeResourceAccess()` and
     * `canAccess(['record' => …])` — the page's `hydrate` checks.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resourceClass
     * @param  class-string<Page>  $pageClass
     */
    private function resolveOwnerRecord(Request $request, string $resourceClass, string $pageClass, int|string $ownerKey, int|string|null $parentKey): Model
    {
        $page = $this->livewire->new($pageClass);
        abort_unless($page instanceof Page, 404);

        // A nested resource's record lives under one parent record, resolved through the PARENT resource's own binding, as
        // on the List endpoint. `mountParentRecord()` (inside
        // `resolveRecord()`) then re-resolves it and authorizes the chain.
        if ($parentRegistration = $resourceClass::getParentResourceRegistration()) {
            abort_if($parentKey === null, 404);

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
