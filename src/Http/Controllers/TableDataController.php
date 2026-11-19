<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Http\Controllers;

use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Zvizvi\Villament\Http\HeadlessTableResponder;
use Zvizvi\Villament\Support\HeadlessTableHarness;
use Zvizvi\Villament\Support\ParentRouteParameters;
use Zvizvi\Villament\Support\ResourceRegistry;

final class TableDataController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly HeadlessTableHarness $harness,
        private readonly HeadlessTableResponder $responder,
        private readonly ParentRouteParameters $parentRouteParameters,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $panel = Filament::getCurrentPanel();
        abort_if($panel === null, 404);

        // Read the resource slug from the named route parameter rather than a
        // method argument: on tenant panels the route also has a `{tenant}`
        // segment, and positional controller-argument binding can bind the
        // tenant value to a `$resource` argument.
        $resource = $request->route('resource');
        abort_unless(is_string($resource), 404);

        $resourceClass = $this->registry->resolve($panel, $resource);
        abort_if($resourceClass === null, 404);

        abort_unless($resourceClass::canViewAny(), 403);

        $pageClass = $this->registry->resolveListPage($resourceClass);
        abort_if($pageClass === null, 404);

        $this->harness->forPage($pageClass);

        // A nested resource's list belongs to one parent record, named by the
        // `parent` query parameter. It is resolved through the PARENT
        // resource's own route binding (its query, so its tenancy and scopes),
        // and Filament's `mountParentRecord()` then re-resolves it and checks
        // `canAccess()` up the whole parent chain during boot, exactly as a
        // page load does.
        if ($parentRegistration = $resourceClass::getParentResourceRegistration()) {
            $parentKey = $request->query('parent');
            abort_unless(is_string($parentKey) && $parentKey !== '', 404);

            $parentRecord = $parentRegistration->getParentResource()::resolveRecordRouteBinding($parentKey);
            abort_if($parentRecord === null, 404);

            $this->harness->withParentRecord($parentRecord);

            $route = $request->route();

            if ($route instanceof Route) {
                $this->parentRouteParameters->fill($route, $resourceClass, $parentRecord);
            }
        }

        return $this->responder->respond($request, $this->harness, $resourceClass);
    }
}
