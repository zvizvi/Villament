<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TabbedUsers;

use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stands in for `zvizvi/filament-column-filters`, which adds a filter from its
 * boot hook for each column visible at that moment - so on this page, only on
 * the `pending` tab.
 */
trait PushesFilterForTheTab
{
    public function bootedPushesFilterForTheTab(): void
    {
        if ($this->activeTab !== 'pending') {
            return;
        }

        $this->getTable()->pushFilters([
            Filter::make('cf_email')->query(fn (Builder $query): Builder => $query->whereNotNull('email')),
        ]);
    }
}
