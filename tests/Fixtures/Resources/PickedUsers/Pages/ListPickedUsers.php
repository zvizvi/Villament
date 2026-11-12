<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\PickedUsers\Pages;

use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Zvizvi\Villament\Pages\VillamentListRecords;
use Zvizvi\Villament\Tests\Fixtures\Resources\PickedUsers\PickedUserResource;

class ListPickedUsers extends VillamentListRecords
{
    protected static string $resource = PickedUserResource::class;

    public ?string $pickedStatus = null;

    /** Declared too, and never written from a request. */
    #[Locked]
    public string $lockedStatus = 'active';

    /**
     * @return list<string>
     */
    public function getVillamentStateProperties(): array
    {
        return ['pickedStatus', 'lockedStatus'];
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();

        return $this->pickedStatus === null ? $query : $query->where('status', $this->pickedStatus);
    }
}
