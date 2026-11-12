<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\LivewireManager;
use ReflectionProperty;
use TypeError;
use Zvizvi\Villament\Events\HeadlessTableBooted;

use function Livewire\wrap;

/**
 * Drives the resource's real List page component headlessly (never rendered):
 * instantiates it, injects table state, fires Filament's own boot hook, and
 * retrieves records through Filament's own pipeline (`getTableRecords()`), so
 * `modifyQueryUsing`, column search callbacks, tenancy scoping and soft-delete
 * handling all apply identically to the stock table.
 */
final class HeadlessTableHarness
{
    private ListRecords $component;

    public function __construct(private readonly LivewireManager $livewire) {}

    /**
     * @param  class-string<ListRecords>  $pageClass
     */
    public function forPage(string $pageClass): self
    {
        $component = $this->livewire->new($pageClass);

        if (! $component instanceof ListRecords) {
            throw new InvalidArgumentException(sprintf(
                '[%s] must be a %s page to drive it headlessly.',
                $pageClass,
                ListRecords::class,
            ));
        }

        $this->component = $component;

        return $this;
    }

    /**
     * Hands a nested resource's page its parent record before boot, where
     * Filament's `mountParentRecord()` takes it from: with no route
     * parameters to read, it re-resolves this record and authorizes it.
     */
    public function withParentRecord(Model $parentRecord): self
    {
        $this->component->parentRecord = $parentRecord;

        return $this;
    }

    /**
     * Sets the page's own query-shaping properties
     * (`HasVillamentTable::getVillamentStateProperties()`) from the request,
     * where Livewire would have hydrated them on a page. Only the declared
     * names are read, a `#[Locked]` property is never written, and a value the
     * property's type refuses is a 422 rather than a crash.
     *
     * @param  array<string, mixed>  $values
     */
    public function withStateProperties(array $values): self
    {
        $component = $this->component;

        if (! method_exists($component, 'getVillamentStateProperties')) {
            return $this;
        }

        foreach ($component->getVillamentStateProperties() as $name) {
            if (! array_key_exists($name, $values) || ! property_exists($component, $name)) {
                continue;
            }

            $property = new ReflectionProperty($component, $name);

            if (! $property->isPublic() || $property->getAttributes(Locked::class) !== []) {
                continue;
            }

            $value = $values[$name];

            if (! (is_scalar($value) || $value === null || (is_array($value) && array_is_list($value) && array_filter($value, fn (mixed $item): bool => ! is_scalar($item)) === []))) {
                throw ValidationException::withMessages(["properties.{$name}" => "The property [{$name}] must be a scalar or a list of scalars."]);
            }

            if ($value === '' && $property->getType()?->allowsNull()) {
                $value = null;
            }

            try {
                $component->{$name} = $value;
            } catch (TypeError) {
                throw ValidationException::withMessages(["properties.{$name}" => "The property [{$name}] does not take that value."]);
            }
        }

        return $this;
    }

    /**
     * Replays Livewire's own boot lifecycle — `boot` / `initialize` / `booted`,
     * component method first then every trait hook, in `class_uses_recursive`
     * order, exactly as `SupportLifecycleHooks` does on mount and hydrate. That
     * is what builds `$this->table` (`bootedInteractsWithTable()`), and it is
     * also the only point at which third-party traits mixed into the page get
     * to touch the table — a package that pushes extra filters from its own
     * `booted<Trait>()` hook would otherwise be invisible here, and the request
     * validator would reject filter state the rendered page happily produced.
     *
     * `mount` is deliberately NOT replayed: skipping it is what keeps the
     * session/query-string hydration inside `mountInteractsWithTable()` off,
     * which is exactly what a stateless endpoint needs.
     */
    public function boot(): self
    {
        $this->callHook('boot');
        $this->callTraitHooks('boot');

        $this->callTraitHooks('initialize');

        $this->callHook('booted');
        $this->callTraitHooks('booted');

        event(new HeadlessTableBooted($this->component));

        return $this;
    }

    private function callHook(string $name): void
    {
        if (method_exists($this->component, $name)) {
            wrap($this->component)->{$name}();
        }
    }

    /**
     * Livewire resolves trait hooks by suffixing the method name with the
     * trait's basename, so the iteration order of `class_uses_recursive()` is
     * the contract a trait author writes against (a trait applied after
     * `InteractsWithTable` may rely on `$this->table` already existing).
     */
    private function callTraitHooks(string $name): void
    {
        foreach (class_uses_recursive($this->component) as $trait) {
            $method = $name.class_basename($trait);

            if (method_exists($this->component, $method)) {
                wrap($this->component)->{$method}();
            }
        }
    }

    public function applyState(TableState $state): self
    {
        $component = $this->component;

        $component->tableSort = $state->sort;
        $component->tableSearch = $state->search;
        $component->tableColumnSearches = $state->columnSearches;

        // A blank grouping falls through to the table's own `defaultGroup()`
        // (mirroring stock, where `tableGrouping` is null until the user picks).
        if ($state->grouping !== null) {
            $component->tableGrouping = $state->grouping;
        }

        // `mount()` (which stock uses to `loadDefaultActiveTab()`) is never
        // replayed here, so the default tab is applied by hand when the request
        // names none — mirroring `loadDefaultActiveTab()`'s own rule.
        $defaultTab = $component->getDefaultActiveTab();
        $component->activeTab = $state->tab ?? ($defaultTab === null ? null : (string) $defaultTab);

        if ($state->filters !== null) {
            $component->tableFilters = array_replace($component->tableFilters ?? [], $state->filters);
        }

        $component->tableRecordsPerPage = $state->perPage ?? $this->getTable()->getDefaultPaginationPageOption();

        $pageName = $component->getTablePaginationPageName();
        $component->setPage($state->page, $pageName);

        AbstractPaginator::currentPageResolver(
            fn (string $currentPageName = 'page'): int => $currentPageName === $pageName ? $state->page : 1,
        );

        AbstractCursorPaginator::currentCursorResolver(
            fn (string $cursorName = 'cursor'): ?Cursor => ($cursorName === $pageName && $state->cursor !== null)
                ? Cursor::fromEncoded($state->cursor)
                : null,
        );

        return $this;
    }

    /**
     * Writes the state Filament would have persisted itself. Stock does this
     * inside `updatedTableSort()` / `updatedTableSearch()` /
     * `updatedTableColumnSearches()`, which a stateless endpoint never fires;
     * without it `persistSortInSession()` and friends would keep READING (the
     * page inlines whatever `mountInteractsWithTable()` restored) while never
     * WRITING — the preference would freeze at its last Livewire-set value.
     *
     * Only the session writes are replicated, never those hooks' other work
     * (`resetPage()`, deselecting records): the request already carries an
     * explicit page and selection.
     */
    public function persistState(): self
    {
        $component = $this->component;
        $table = $this->getTable();

        if ($table->persistsSortInSession()) {
            session()->put($component->getTableSortSessionKey(), $component->tableSort);
        }

        if ($table->persistsSearchInSession()) {
            session()->put($component->getTableSearchSessionKey(), $component->tableSearch);
        }

        if ($table->persistsColumnSearchesInSession()) {
            session()->put($component->getTableColumnSearchesSessionKey(), $component->tableColumnSearches);
        }

        return $this;
    }

    public function getTable(): Table
    {
        return $this->component->getTable();
    }

    public function getComponent(): ListRecords
    {
        return $this->component;
    }

    /**
     * @return Collection<int, mixed>|Paginator<int, mixed>|CursorPaginator<int, mixed>
     */
    public function getRecords(): Collection|Paginator|CursorPaginator
    {
        return $this->component->getTableRecords();
    }
}
