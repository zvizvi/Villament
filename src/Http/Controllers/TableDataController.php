<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Http\Controllers;

use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Zvizvi\Villament\Support\HeadlessTableHarness;
use Zvizvi\Villament\Support\RequestStateValidator;
use Zvizvi\Villament\Support\ResourceRegistry;
use Zvizvi\Villament\Support\TablePayload;

final class TableDataController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly HeadlessTableHarness $harness,
        private readonly RequestStateValidator $validator,
        private readonly TablePayload $payload,
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
        }

        // The page's own query-shaping properties, BEFORE boot: the table is
        // built during boot, and a table may decide which filters exist from
        // them (filters offered only for a picked campaign, say) — set after,
        // the island's state would name filters the endpoint never built.
        $properties = $request->query('properties');
        $this->harness->withStateProperties(is_array($properties) ? $properties : []);

        $this->harness->boot();

        $table = $this->harness->getTable();
        $state = $this->validator->validate($request, $table);
        $this->harness->applyState($state)->persistState();

        $cacheTtl = (int) config('villament.cache_ttl', 0);

        $response = response()->json(
            $this->payload->for($this->harness->getComponent(), $resourceClass),
        );

        if ($cacheTtl > 0) {
            $response->header('Cache-Control', "private, max-age={$cacheTtl}");
        }

        return $response;
    }
}
