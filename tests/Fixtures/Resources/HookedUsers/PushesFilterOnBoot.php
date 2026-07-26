<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\HookedUsers;

use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Stands in for third-party packages (e.g. header-filter plugins) that add
 * filters to the table from their own Livewire `booted<Trait>()` hook rather
 * than from `table()`. Livewire fires these on every request; a headless driver
 * that only calls `bootedInteractsWithTable()` would never see the filter.
 */
trait PushesFilterOnBoot
{
    /**
     * Stands in for a header-decorating plugin (`filament-column-filters`):
     * it rewrites the column label on Livewire's `render` event, which the
     * rendered page fires and the data endpoint never can.
     */
    public function renderingPushesFilterOnBoot(): void
    {
        $this->getTable()->getColumn('name')?->label(
            new HtmlString('<span class="decorated">Name<span class="trigger"></span></span>'),
        );
    }

    public function bootedPushesFilterOnBoot(): void
    {
        $this->getTable()->pushFilters([
            Filter::make('pushed_name')
                ->schema([TextInput::make('name')])
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    $data['name'] ?? null,
                    fn (Builder $query, string $name): Builder => $query->where('name', $name),
                )),
        ]);
    }
}
