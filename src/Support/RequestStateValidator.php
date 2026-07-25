<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Tables\Table;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;

/**
 * Whitelists every request parameter against the table schema before any of
 * it can touch the query. Violations abort with a 422 — never silently
 * ignored, so client bugs (or probing) surface immediately.
 */
final class RequestStateValidator
{
    public function validate(Request $request, Table $table): TableState
    {
        return new TableState(
            sort: $this->validateSort($request, $table),
            search: $this->validateSearch($request, $table),
            columnSearches: $this->validateColumnSearches($request, $table),
            filters: $this->validateFilters($request, $table),
            page: $this->validatePage($request),
            perPage: $this->validatePerPage($request, $table),
            cursor: $this->validateCursor($request),
            grouping: $this->validateGrouping($request, $table),
        );
    }

    /**
     * Whitelists the `tableGrouping` string against the table's registered
     * groups: the id must be one Filament exposes, the optional direction must
     * be asc/desc. A blank param leaves grouping untouched (falls back to the
     * table's own `defaultGroup()`).
     */
    private function validateGrouping(Request $request, Table $table): ?string
    {
        $grouping = $request->query('grouping');

        if (blank($grouping)) {
            return null;
        }

        if (! is_string($grouping)) {
            $this->reject('grouping', 'The grouping parameter must be a string.');
        }

        [$id, $direction] = array_pad(explode(':', $grouping, 2), 2, 'asc');

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $this->reject('grouping', "The grouping direction [{$direction}] is invalid.");
        }

        if ($table->getGroup($id) === null) {
            $this->reject('grouping', "The group [{$id}] does not exist.");
        }

        return "{$id}:{$direction}";
    }

    private function validateSort(Request $request, Table $table): ?string
    {
        $sort = $request->query('sort');

        if (blank($sort)) {
            return null;
        }

        if (! is_string($sort)) {
            $this->reject('sort', 'The sort parameter must be a string.');
        }

        [$column, $direction] = array_pad(explode(':', $sort, 2), 2, 'asc');

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $this->reject('sort', "The sort direction [{$direction}] is invalid.");
        }

        $tableColumn = $table->getColumn($column);

        if ($tableColumn === null || ! $tableColumn->isSortable()) {
            $this->reject('sort', "The column [{$column}] is not sortable.");
        }

        return "{$column}:{$direction}";
    }

    private function validateSearch(Request $request, Table $table): string
    {
        $search = $request->query('search');

        if (blank($search)) {
            return '';
        }

        if (! is_string($search)) {
            $this->reject('search', 'The search parameter must be a string.');
        }

        if (! $table->isSearchable()) {
            $this->reject('search', 'This table is not globally searchable.');
        }

        return $search;
    }

    /**
     * @return array<string, string>
     */
    private function validateColumnSearches(Request $request, Table $table): array
    {
        $columnSearches = $request->query('columnSearches');

        if (blank($columnSearches)) {
            return [];
        }

        if (! is_array($columnSearches)) {
            $this->reject('columnSearches', 'The columnSearches parameter must be an array.');
        }

        $validated = [];

        foreach ($columnSearches as $columnName => $search) {
            $column = $table->getColumn((string) $columnName);

            if ($column === null || ! $column->isIndividuallySearchable()) {
                $this->reject("columnSearches.{$columnName}", "The column [{$columnName}] is not individually searchable.");
            }

            if (! is_string($search)) {
                $this->reject("columnSearches.{$columnName}", 'Column search values must be strings.');
            }

            if (filled($search)) {
                $validated[(string) $columnName] = $search;
            }
        }

        return $validated;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function validateFilters(Request $request, Table $table): ?array
    {
        $filters = $request->query('filters');

        if (blank($filters)) {
            return null;
        }

        if (! is_array($filters)) {
            $this->reject('filters', 'The filters parameter must be an array.');
        }

        $validated = [];

        foreach ($filters as $filterName => $state) {
            if ($table->getFilter((string) $filterName) === null) {
                $this->reject("filters.{$filterName}", "The filter [{$filterName}] does not exist.");
            }

            if (! is_array($state)) {
                $this->reject("filters.{$filterName}", 'Filter state must be an array of form values.');
            }

            $validated[(string) $filterName] = $state;
        }

        return $validated;
    }

    private function validatePage(Request $request): int
    {
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT);

        if ($page === false || $page < 1) {
            $this->reject('page', 'The page parameter must be a positive integer.');
        }

        return $page;
    }

    private function validatePerPage(Request $request, Table $table): int|string|null
    {
        $perPage = $request->query('perPage');

        if (blank($perPage)) {
            return null;
        }

        $options = $table->getPaginationPageOptions();

        if ($perPage === 'all' && in_array('all', $options, true)) {
            return 'all';
        }

        $perPage = filter_var($perPage, FILTER_VALIDATE_INT);

        if ($perPage !== false && in_array($perPage, $options, true)) {
            return $perPage;
        }

        $this->reject('perPage', 'The perPage value is not one of the allowed pagination options.');
    }

    private function validateCursor(Request $request): ?string
    {
        $cursor = $request->query('cursor');

        if (blank($cursor)) {
            return null;
        }

        if (! is_string($cursor)) {
            $this->reject('cursor', 'The cursor parameter must be a string.');
        }

        try {
            if (Cursor::fromEncoded($cursor) === null) {
                $this->reject('cursor', 'The cursor is malformed.');
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            $this->reject('cursor', 'The cursor is malformed.');
        }

        return $cursor;
    }

    private function reject(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
