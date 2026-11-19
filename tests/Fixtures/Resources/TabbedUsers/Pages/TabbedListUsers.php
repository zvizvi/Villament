<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TabbedUsers\Pages;

use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\Villament\Pages\VillamentListRecords;
use Zvizvi\Villament\Tests\Fixtures\Resources\TabbedUsers\PushesFilterForTheTab;
use Zvizvi\Villament\Tests\Fixtures\Resources\TabbedUsers\TabbedUserResource;

class TabbedListUsers extends VillamentListRecords
{
    use PushesFilterForTheTab;

    protected static string $resource = TabbedUserResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'pending')),
        ];
    }
}
