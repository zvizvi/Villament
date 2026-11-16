<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Resources\Pages\Page;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Livewire\LivewireManager;
use Zvizvi\Villament\Concerns\HasVillamentRelationTable;

/**
 * Resolves what the relation endpoint serves from what the request names —
 * a page of the resource, and a relation manager on it — without ever
 * looking a class up from user input. Everything is matched against what
 * Filament itself would render for that owner record:
 *
 * - the page is one of the resource's own `getPages()`, by its registration key;
 * - the relation manager is one the page's own `getRelationManagers()`
 *   returns for that record, with relation groups unpacked the way
 *   `HasRelationManagers` does it — both already filter by
 *   `canViewForRecord()`, so a manager the user may not see is unknown here;
 * - and it carries {@see HasVillamentRelationTable}: a relation manager that
 *   never opted in has no island and no endpoint.
 */
final class RelationRegistry
{
    public function __construct(private readonly LivewireManager $livewire) {}

    /**
     * The registration key of a page in its resource's `getPages()`.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resourceClass
     */
    public function pageKeyFor(string $resourceClass, string $pageClass): ?string
    {
        foreach ($resourceClass::getPages() as $key => $registration) {
            if ($registration->getPage() === $pageClass) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resourceClass
     * @return class-string<Page>|null
     */
    public function resolvePage(string $resourceClass, string $pageKey): ?string
    {
        $pages = $resourceClass::getPages();

        if (! array_key_exists($pageKey, $pages)) {
            return null;
        }

        $pageClass = $pages[$pageKey]->getPage();

        return is_subclass_of($pageClass, Page::class) ? $pageClass : null;
    }

    /**
     * A ManageRelatedRecords page with an island, or null.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resourceClass
     * @return class-string<ManageRelatedRecords>|null
     */
    public function resolveRelatedRecordsPage(string $resourceClass, string $pageKey): ?string
    {
        $pageClass = $this->resolvePage($resourceClass, $pageKey);

        if (
            $pageClass === null
            || ! is_subclass_of($pageClass, ManageRelatedRecords::class)
            || ! in_array(HasVillamentRelationTable::class, class_uses_recursive($pageClass), true)
        ) {
            return null;
        }

        return $pageClass;
    }

    /**
     * The relation managers a page shows for this owner record, keyed by
     * their endpoint key (see {@see keyFor()}), each with the properties its
     * `RelationManagerConfiguration` mounts it with.
     *
     * @param  class-string<Page>  $pageClass
     * @return array<string, array{class: class-string<RelationManager>, properties: array<string, mixed>}>
     */
    public function managersFor(string $pageClass, Model $ownerRecord): array
    {
        $page = $this->livewire->new($pageClass);

        if (! $page instanceof Page || ! method_exists($page, 'getRelationManagers') || ! property_exists($page, 'record')) {
            return [];
        }

        // What `mount()` would have set; nothing else of the page is needed
        // to ask it which managers it shows.
        $page->record = $ownerRecord;

        $managers = [];
        $occurrences = [];

        foreach ($page->getRelationManagers() as $manager) {
            $entries = $manager instanceof RelationGroup
                ? $manager->ownerRecord($ownerRecord)->pageClass($pageClass)->getManagers()
                : [$manager];

            foreach ($entries as $entry) {
                $class = $entry instanceof RelationManagerConfiguration ? $entry->relationManager : $entry;

                if (! is_string($class) || ! is_subclass_of($class, RelationManager::class)) {
                    continue;
                }

                $occurrence = $occurrences[$class] = ($occurrences[$class] ?? -1) + 1;

                $managers[$this->keyFor($class, $occurrence)] = [
                    'class' => $class,
                    'properties' => [
                        ...$class::getDefaultProperties(),
                        ...($entry instanceof RelationManagerConfiguration ? $entry->getProperties() : []),
                    ],
                ];
            }
        }

        return $managers;
    }

    /**
     * @param  class-string<Page>  $pageClass
     * @return array{class: class-string<RelationManager>, properties: array<string, mixed>}|null
     */
    public function resolveRelationManager(string $pageClass, Model $ownerRecord, string $key): ?array
    {
        $manager = $this->managersFor($pageClass, $ownerRecord)[$key] ?? null;

        if ($manager === null || ! in_array(HasVillamentRelationTable::class, class_uses_recursive($manager['class']), true)) {
            return null;
        }

        return $manager;
    }

    /**
     * The endpoint's name for a relation manager on a page: its class
     * basename in kebab case. The same class registered more than once (two
     * `RelationManagerConfiguration`s of it, say) gets `-1`, `-2`… for every
     * occurrence after the first, in the page's own order.
     *
     * @param  class-string<RelationManager>  $class
     */
    public function keyFor(string $class, int $occurrence = 0): string
    {
        $key = (string) str(class_basename($class))->kebab();

        return $occurrence === 0 ? $key : "{$key}-{$occurrence}";
    }

    /**
     * The key of this very component among its page's managers: the first
     * registration of its class whose configured properties all match the
     * values the component was mounted with.
     */
    public function keyForComponent(RelationManager $component): ?string
    {
        $pageClass = $component->getPageClass();

        if (! is_subclass_of($pageClass, Page::class)) {
            return null;
        }

        foreach ($this->managersFor($pageClass, $component->getOwnerRecord()) as $key => $manager) {
            if ($manager['class'] !== $component::class) {
                continue;
            }

            foreach ($manager['properties'] as $name => $value) {
                if ($name !== 'lazy' && property_exists($component, $name) && $component->{$name} != $value) {
                    continue 2;
                }
            }

            return $key;
        }

        return null;
    }
}
