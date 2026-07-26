<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\HookedUsers\Pages;

use Zvizvi\Villament\Pages\VillamentListRecords;
use Zvizvi\Villament\Tests\Fixtures\Resources\HookedUsers\HookedUserResource;
use Zvizvi\Villament\Tests\Fixtures\Resources\HookedUsers\PushesFilterOnBoot;

class HookedListUsers extends VillamentListRecords
{
    use PushesFilterOnBoot;

    protected static string $resource = HookedUserResource::class;

    /**
     * @return array<string, string>
     */
    public function getVillamentColumnFilterSlots(): array
    {
        return ['name' => 'fi-ta-header-filter-cell fi-ta-header-filter-cell-name'];
    }
}
