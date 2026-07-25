<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\LivewireManager;

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
     * Fires `bootedInteractsWithTable()` — the same Livewire `booted` hook the
     * real page relies on to build `$this->table`. The session/query-string
     * hydration inside it is skipped because `mountInteractsWithTable()` never
     * ran, which is exactly what a stateless endpoint needs.
     */
    public function boot(): self
    {
        $this->component->bootedInteractsWithTable();

        return $this;
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
