<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;

/**
 * Gives the endpoint's own route the parent route parameters a nested
 * resource's page has (`/teams/{team}/users`).
 *
 * Filament builds a nested resource's URLs — a record URL, an action URL —
 * by guessing each missing parent parameter from the CURRENT route
 * (`Resource::getUrl(shouldGuessMissingParameters: true)` reads
 * `original_request()->route()`). On the page those parameters are there; on
 * the data endpoint they are not, and the first record URL throws a missing
 * parameter error. The endpoint knows the parent record (resolved and
 * authorized through the parent resource), so it sets them, up the whole
 * parent chain, before anything is serialized.
 */
final class ParentRouteParameters
{
    /**
     * @param  class-string<\Filament\Resources\Resource>  $resourceClass  the nested resource
     */
    public function fill(Route $route, string $resourceClass, Model $parentRecord): void
    {
        $registration = $resourceClass::getParentResourceRegistration();
        $record = $parentRecord;

        while ($registration !== null && $record instanceof Model) {
            $parentResource = $registration->getParentResource();
            $name = $registration->getParentRouteParameterName();

            if (! $route->hasParameter($name)) {
                $route->setParameter($name, filled($routeKeyName = $parentResource::getRecordRouteKeyName())
                    ? $record->getAttribute($routeKeyName)
                    : $record->getRouteKey());
            }

            $registration = $parentResource::getParentResourceRegistration();
            $record = $registration === null ? null : $record->{$registration->getInverseRelationshipName()};
        }
    }
}
