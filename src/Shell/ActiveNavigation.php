<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Shell;

use Filament\Pages\Page;
use Filament\Resources\Pages\Page as ResourcePage;
use Throwable;

/**
 * The navigation URLs the current page makes active, without building the
 * navigation. A kept sidebar still shows the previous page's active item;
 * the browser re-marks it from these.
 *
 * Stock marks a resource's item for every page of the resource (and of the
 * resources nested under it), a page's item for that page, and a cluster's
 * item for everything inside the cluster — `getNavigationItemActiveRoutePattern()`.
 * Those are exactly the navigation URLs of the top-level resource, the page
 * and the cluster. An item made active some other way
 * (`NavigationItem::isActiveWhen()`) is not covered.
 */
final class ActiveNavigation
{
    /**
     * @return list<string>
     */
    public static function urls(): array
    {
        $page = request()->route()?->getAction('controller');

        if (! is_string($page) || ! class_exists($page)) {
            return [];
        }

        $urls = [];

        try {
            if (is_subclass_of($page, ResourcePage::class)) {
                $resource = $page::getResource();

                while ($parent = $resource::getParentResourceRegistration()) {
                    $resource = $parent->getParentResource();
                }

                $urls[] = $resource::getNavigationUrl();
                $cluster = $resource::getCluster();
            } elseif (is_subclass_of($page, Page::class)) {
                $urls[] = $page::getNavigationUrl();
                $cluster = $page::getCluster();
            } else {
                return [];
            }

            if ($cluster !== null) {
                $urls[] = $cluster::getNavigationUrl();
            }
        } catch (Throwable) {
            // A URL that needs parameters this request cannot give: the
            // browser leaves the marks as they are rather than guess.
            return [];
        }

        return array_values(array_unique($urls));
    }
}
