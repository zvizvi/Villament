<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\HookedUsers;

use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stands in for third-party packages (e.g. header-filter plugins) that add
 * filters to the table from their own Livewire `booted<Trait>()` hook rather
 * than from `table()`. Livewire fires these on every request; a headless driver
 * that only calls `bootedInteractsWithTable()` would never see the filter.
 */
trait PushesFilterOnBoot
{
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
